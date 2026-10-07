<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Models\Customer;
use App\Models\User;
use App\Support\Customers\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * All values are fictitious (AGENTS.md section 8): no real customers, phones or documents.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CustomerType::Natural,
            'name' => fake()->name(),
            'document_type' => null,
            'document_number' => null,
            'phone' => fake()->unique()->numerify('+58414#######'),
            'email' => fake()->unique()->safeEmail(),
            'status' => CustomerStatus::Active,
            'created_by' => User::factory(),
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'type' => CustomerType::Company,
            'name' => fake()->company(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => CustomerStatus::Inactive]);
    }

    /**
     * A valid synthetic document in canonical form (RIFs with the correct check digit). Without an
     * explicit type it follows the customer type of the states applied before it: cédula V for
     * natural, RIF J for company (so call it after company()).
     */
    public function withDocument(?DocumentType $type = null): static
    {
        return $this->state(function (array $attributes) use ($type) {
            $documentType = $type ?? (($attributes['type'] ?? null) === CustomerType::Company ? DocumentType::RifJ : DocumentType::CedulaV);

            return [
                'document_type' => $documentType,
                'document_number' => $this->syntheticDocumentNumber($documentType),
            ];
        });
    }

    public function withContact(): static
    {
        return $this->has(CustomerContactFactory::new(), 'contact');
    }

    public function withAddress(): static
    {
        return $this->has(CustomerAddressFactory::new(), 'address');
    }

    public function assignedTo(User $advisor): static
    {
        return $this->state(fn () => ['advisor_id' => $advisor->id]);
    }

    private function syntheticDocumentNumber(DocumentType $type): string
    {
        $letter = $type->letter();

        if ($letter === null) {
            return strtoupper(fake()->unique()->bothify('??#######'));
        }

        $digits = (string) fake()->unique()->numberBetween(10000000, 99999999);

        if (! $type->isRif()) {
            return $letter.$digits;
        }

        return $letter.$digits.DocumentNumber::rifCheckDigit($letter, $digits);
    }
}
