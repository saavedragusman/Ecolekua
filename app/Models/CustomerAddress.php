<?php

namespace App\Models;

use App\Enums\VenezuelanState;
use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Address of a customer (CLI-006, DEC-CLI-17). Part of the customer aggregate; the state is one
 * of the 24 Venezuelan federal entities (DEC-CLI-33) and the city is free text.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $line
 * @property string $city
 * @property VenezuelanState $state
 * @property string|null $reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 */
#[Fillable(['customer_id', 'line', 'city', 'state', 'reference'])]
class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => VenezuelanState::class,
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
