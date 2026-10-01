<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Support\Customers\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A customer of Ecolekua (spec 002). Phone is stored in E.164 and the document in its canonical
 * form (letter included, no separators); both are normalized by the Actions, never by the model.
 *
 * @property int $id
 * @property CustomerType $type
 * @property string $name
 * @property DocumentType|null $document_type
 * @property string|null $document_number
 * @property string $phone
 * @property string|null $email
 * @property int|null $birthday_day
 * @property int|null $birthday_month
 * @property int|null $anniversary_day
 * @property int|null $anniversary_month
 * @property string|null $notes
 * @property int|null $advisor_id
 * @property CustomerStatus $status
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CustomerContact|null $contact
 * @property-read CustomerAddress|null $address
 * @property-read User|null $advisor
 * @property-read User $creator
 */
#[Fillable([
    'type', 'name', 'document_type', 'document_number', 'phone', 'email',
    'birthday_day', 'birthday_month', 'anniversary_day', 'anniversary_month',
    'notes', 'advisor_id', 'status', 'created_by',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'document_type' => DocumentType::class,
            'birthday_day' => 'integer',
            'birthday_month' => 'integer',
            'anniversary_day' => 'integer',
            'anniversary_month' => 'integer',
        ];
    }

    /**
     * @return HasOne<CustomerContact, $this>
     */
    public function contact(): HasOne
    {
        return $this->hasOne(CustomerContact::class);
    }

    /**
     * @return HasOne<CustomerAddress, $this>
     */
    public function address(): HasOne
    {
        return $this->hasOne(CustomerAddress::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Customer>  $query
     */
    public function scopeWithStatus(Builder $query, CustomerStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * List search (design Decision 13): a customer matches when its name, its document or its phone
     * contains the term. `%` and `_` are escaped so they match literally; a blank term filters nothing.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', self::contains($term));

            // Documents are stored canonical (`J123456784`): compare without spaces, dots and hyphens.
            $document = strtoupper(str_replace([' ', '.', '-'], '', $term));
            if ($document !== '') {
                $query->orWhere('document_number', 'like', self::contains($document));
            }

            // `J-1234` also matches by its digits, so `1234` and `J-1234` find the same documents.
            if (preg_match('/^[A-Z](\d+)$/', $document, $matches) === 1) {
                $query->orWhere('document_number', 'like', self::contains($matches[1]));
            }

            $fragment = PhoneNumber::searchFragment($term);

            if ($fragment !== null) {
                $query->orWhere('phone', 'like', self::contains($fragment));
            }
        });
    }

    /**
     * @param  Builder<Customer>  $query
     */
    public function scopeAssignedTo(Builder $query, User $advisor): void
    {
        $query->where('advisor_id', $advisor->getKey());
    }

    private static function contains(string $value): string
    {
        return '%'.addcslashes($value, '\\%_').'%';
    }
}
