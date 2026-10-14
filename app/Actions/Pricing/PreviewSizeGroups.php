<?php

namespace App\Actions\Pricing;

use App\Models\Combination;
use App\Support\Pricing\Money;
use App\Support\Pricing\SizeGroupState;
use Illuminate\Validation\ValidationException;

/**
 * First step of a change of size groups (COT-004, DEC-COT-53, E-70, design Decision 10): tells what
 * saving the proposed groups would do, so the screen can ask for confirmation before the PUT. It
 * only reads: no lock, no write, no audit. The prices come from the same `SizeGroupPlan` the save
 * recomputes under the product lock, with the amounts as strings (`value` canonical, `display` es-VE).
 */
class PreviewSizeGroups
{
    /**
     * @param  list<list<int>>  $groups  proposed groups (size value ids); an empty list removes the grouping
     * @return array{requires_confirmation: bool, deleted_prices: list<array{group: ?string, tier: string, price: array{value: string, display: string}}>, confirm_token: ?string}
     *
     * @throws ValidationException when the combination takes no groups or the groups do not partition its sizes (E-08)
     */
    public function handle(Combination $combination, array $groups): array
    {
        $plan = SizeGroupState::loadValidated($combination, $groups)->diff($groups);

        return [
            'requires_confirmation' => $plan->requiresConfirmation(),
            'deleted_prices' => array_map(fn (array $row): array => [
                'group' => $row['group'],
                'tier' => $row['tier'],
                'price' => ['value' => $row['price'], 'display' => Money::display($row['price'])],
            ], $plan->deletedPrices()),
            'confirm_token' => $plan->confirmToken(),
        ];
    }
}
