<?php

namespace Database\Factories;

use App\Enums\ExchangeRateOrigin;
use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An effective API rate of a past day, each one on its own date so two factory rows never compete for
 * the effective rate of a day. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_date' => now()->subDays(fake()->unique()->numberBetween(30, 100_000))->toDateString(),
            'value' => '100.0000',
            'origin' => ExchangeRateOrigin::Api,
        ];
    }
}
