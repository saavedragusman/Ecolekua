<?php

namespace Database\Seeders;

use App\Actions\Pricing\CreatePriceScheme;
use App\Models\PriceScheme;
use App\Support\Audit\AuditOrigin;
use Illuminate\Database\Seeder;

/**
 * The four initial price schemes of Ecolekua (COT-001, DEC-COT-05, DEC-COT-26, design Decision 8).
 * Idempotent: a scheme whose name already exists (case-insensitively) is left as it is, so the seeder
 * is safe in the deploy step. Only labels and limits are written, never prices. Each created scheme
 * is audited as `pricing.scheme_created` with console origin.
 */
class PriceSchemeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, type: string, tiers?: list<array{label: string, min: int, max: ?int, kind: string}>}>
     */
    private const SCHEMES = [
        ['name' => 'Uniformes', 'type' => 'tiered', 'tiers' => [
            ['label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
            ['label' => '7–12', 'min' => 7, 'max' => 12, 'kind' => 'priced'],
            ['label' => '13–49', 'min' => 13, 'max' => 49, 'kind' => 'priced'],
            ['label' => 'Negociación', 'min' => 50, 'max' => null, 'kind' => 'negotiation'],
        ]],
        ['name' => 'Pañales de adulto', 'type' => 'tiered', 'tiers' => [
            ['label' => 'Detal', 'min' => 1, 'max' => 19, 'kind' => 'priced'],
            ['label' => 'Especial', 'min' => 20, 'max' => null, 'kind' => 'priced'],
        ]],
        ['name' => 'Protector y absorbentes', 'type' => 'tiered', 'tiers' => [
            ['label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
            ['label' => 'Especial', 'min' => 7, 'max' => null, 'kind' => 'priced'],
        ]],
        ['name' => 'Precio fijo', 'type' => 'fixed'],
    ];

    public function run(CreatePriceScheme $createPriceScheme): void
    {
        foreach (self::SCHEMES as $scheme) {
            if (PriceScheme::query()->where('name', $scheme['name'])->exists()) {
                continue;
            }

            $createPriceScheme->handle($scheme, null, AuditOrigin::console('db:seed'));
        }
    }
}
