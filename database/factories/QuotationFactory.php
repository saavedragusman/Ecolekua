<?php

namespace Database\Factories;

use App\Enums\QuotationOrigin;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft quotation for a new customer, created by an advisor. `prospect()` addresses it to a prospect
 * instead; `issued()` and `expired()` give it those states with their dates. All values are fictitious
 * (AGENTS.md section 8).
 *
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 9_999_999),
            'status' => QuotationStatus::Draft,
            'origin' => QuotationOrigin::Advisor,
            'customer_id' => Customer::factory(),
            'include_contact' => false,
            'prospect_name' => null,
            'prospect_phone' => null,
            'prospect_email' => null,
            'created_by' => User::factory(),
            'advisor_id' => null,
        ];
    }

    public function prospect(): static
    {
        return $this->state([
            'customer_id' => null,
            'prospect_name' => 'Prospecto '.fake()->unique()->bothify('??-###'),
            'prospect_phone' => fake()->unique()->numerify('+58414#######'),
            'prospect_email' => null,
        ]);
    }

    public function issued(): static
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Issued,
            'issued_at' => now(),
            'expires_on' => now()->addDays(7)->toDateString(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Expired,
            'issued_at' => now()->subDays(10),
            'expires_on' => now()->subDays(3)->toDateString(),
            'expired_at' => now()->subDays(2),
        ]);
    }
}
