<?php

namespace App\Models;

use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Contact person of a company customer (CLI-005). Part of the customer aggregate: it is
 * created, replaced and deleted with it. The phone is stored in E.164.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $position
 * @property string $phone
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 */
#[Fillable(['customer_id', 'name', 'position', 'phone', 'email'])]
class CustomerContact extends Model
{
    /** @use HasFactory<CustomerContactFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
