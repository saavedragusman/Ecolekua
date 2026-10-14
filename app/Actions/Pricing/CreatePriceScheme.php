<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\PriceScheme;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Pricing\SchemeAudit;
use App\Support\Pricing\SchemeInput;
use App\Support\Pricing\SchemeRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a price scheme, active, with its tiers (COT-001, E-01, design Decision 8). The `type` is
 * set here and never changes (DEC-COT-51). A tiered scheme takes the ordered tier list, checked by
 * `SchemeRules` before anything is written (E-02); a fixed scheme gets one hidden system tier
 * ("Precio", from 1, open, priced) so every price stays keyed by a tier. The seeder calls it with a
 * console origin and no actor.
 */
class CreatePriceScheme
{
    /** Label of the single system tier of a fixed scheme. */
    public const FIXED_TIER_LABEL = 'Precio';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`, `type`, and `tiers` for a tiered scheme)
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the tiers are invalid (`tiers`, `tiers.{i}.{field}`) or the name is taken (`name`)
     */
    public function handle(array $data, ?User $actor, ?AuditOrigin $origin = null, array $auditContext = []): PriceScheme
    {
        $type = PriceSchemeType::from((string) $data['type']);
        $name = (string) $data['name'];
        $tiers = $type === PriceSchemeType::Fixed
            ? [['label' => self::FIXED_TIER_LABEL, 'min' => 1, 'max' => null, 'kind' => PriceTierKind::Priced->value]]
            : $this->validTiers($data['tiers'] ?? []);

        try {
            return DB::transaction(function () use ($name, $type, $tiers, $actor, $origin, $auditContext): PriceScheme {
                $scheme = PriceScheme::query()->create([
                    'name' => $name,
                    'type' => $type,
                    'status' => CatalogStatus::Active,
                ]);

                foreach ($tiers as $position => $tier) {
                    $scheme->tiers()->create([
                        'label' => $tier['label'],
                        'min_quantity' => $tier['min'],
                        'max_quantity' => $tier['max'],
                        'kind' => PriceTierKind::from($tier['kind']),
                        'sort_order' => $position + 1,
                    ]);
                }

                $this->audit->handle(
                    AuditAction::PriceSchemeCreated,
                    $actor,
                    $scheme,
                    newValues: [
                        'name' => $scheme->name,
                        'type' => $type->value,
                        'status' => CatalogStatus::Active->value,
                        'tiers' => SchemeAudit::tiers($scheme->load('tiers')),
                    ],
                    context: $auditContext,
                    origin: $origin,
                );

                return $scheme;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.scheme_name_unique')]);
        }
    }

    /**
     * @return list<array{id: ?int, label: string, min: int, max: ?int, kind: string}>
     *
     * @throws ValidationException
     */
    private function validTiers(mixed $submitted): array
    {
        $tiers = SchemeInput::normalizeTiers($submitted);
        $errors = SchemeRules::validateTiers($tiers);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $tiers;
    }
}
