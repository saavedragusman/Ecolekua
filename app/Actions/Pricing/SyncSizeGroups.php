<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\Product;
use App\Models\SizeGroup;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Pricing\SizeGroupPlan;
use App\Support\Pricing\SizeGroupState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Second step of a change of size groups (COT-004, DEC-COT-53, DEC-COT-58, E-08, E-70, design
 * Decision 10): saves the groups of a combination.
 *
 * - The product row is locked (the lock price writes and 003 combination writes take), the state is
 *   read again under it and the plan is recomputed from it, never taken from the preview.
 * - The groups must partition the sizes the combination admits (`SizeGroupRules`).
 * - When the plan deletes prices the request must carry the token of that exact deletion: without it
 *   nothing changes; with a stale one ("los precios cambiaron") nothing changes either, so the user
 *   always confirms exactly what is deleted.
 * - Otherwise, in the same transaction: the listed prices are deleted, dropped groups go, kept groups
 *   take their new order and any newly assigned size (DEC-COT-58), new groups are created, and one
 *   `pricing.size_groups_updated` audit row keeps the partitions and the deleted prices.
 * - Saving the groups the combination already has writes and audits nothing.
 */
class SyncSizeGroups
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<list<int>>  $groups  proposed groups (size value ids); an empty list removes the grouping
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the groups are invalid or the deletion of prices is not confirmed
     */
    public function handle(Combination $combination, array $groups, ?string $confirmToken, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): void
    {
        DB::transaction(function () use ($combination, $groups, $confirmToken, $actor, $origin, $auditContext): void {
            Product::query()->lockForUpdate()->findOrFail($combination->product_id);
            $combination = Combination::query()->with('catalogCode')->findOrFail($combination->id);

            $state = SizeGroupState::loadValidated($combination, $groups);

            if ($state->isSameAs($groups)) {
                return;
            }

            $plan = $state->diff($groups);
            $this->ensureConfirmed($plan, $confirmToken);

            CombinationPrice::query()->whereKey($plan->deletedPriceIds())->delete();
            SizeGroup::query()->whereKey($plan->droppedGroupIds())->delete();
            $this->saveGroups($combination, $groups, $plan);

            $this->audit->handle(
                AuditAction::SizeGroupsUpdated,
                $actor,
                $combination,
                oldValues: [
                    'partition' => $state->partition(),
                    'deleted_prices' => array_map(fn (array $row): array => [
                        'code' => $combination->code,
                        'size_group' => $row['group'],
                        'tier' => $row['tier'],
                        'price' => $row['price'],
                    ], $plan->deletedPrices()),
                ],
                newValues: ['partition' => array_map(fn (array $ids): array => array_values(array_intersect_key($state->palette, array_flip($ids))), $groups)],
                context: $auditContext,
                origin: $origin,
            );
        });
    }

    /**
     * @throws ValidationException when the plan deletes prices and the token is missing or does not match
     */
    private function ensureConfirmed(SizeGroupPlan $plan, ?string $confirmToken): void
    {
        if (! $plan->requiresConfirmation()) {
            return;
        }

        if ($confirmToken === null) {
            throw ValidationException::withMessages(['groups' => Lang::string('validation.size_groups_confirmation_required')]);
        }

        if (! hash_equals((string) $plan->confirmToken(), $confirmToken)) {
            throw ValidationException::withMessages(['groups' => Lang::string('validation.size_groups_prices_changed')]);
        }
    }

    /**
     * Kept groups keep their id and prices (their order and sizes are updated), the others are created.
     *
     * @param  list<list<int>>  $groups
     */
    private function saveGroups(Combination $combination, array $groups, SizeGroupPlan $plan): void
    {
        $kept = SizeGroup::query()->whereKey(array_values($plan->keptGroups()))->get()->keyBy('id');

        foreach ($groups as $index => $sizeIds) {
            $stored = $plan->keptGroups()[$index] ?? null;
            $group = $stored === null ? new SizeGroup(['combination_id' => $combination->id]) : $kept->get($stored);

            $group->fill(['sort_order' => $index + 1])->save();
            $group->sizes()->sync(array_fill_keys($sizeIds, ['combination_id' => $combination->id]));
        }
    }
}
