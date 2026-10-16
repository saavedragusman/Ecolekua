<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use App\Enums\QuotationOrigin;
use App\Enums\QuotationStatus;
use Brick\Math\BigDecimal;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A quotation of the ERP (COT-013, design Decision 4). A draft keeps what the user chose and the cache of its
 * last calculation; issuing it freezes a copy of every amount, the rate and the recipient (COT-016). The
 * recipient is a customer or a prospect, never both. Money is a `BigDecimal` (DT-01).
 *
 * @property int $id
 * @property int $number
 * @property QuotationStatus $status
 * @property QuotationOrigin $origin
 * @property int|null $customer_id
 * @property bool $include_contact
 * @property string|null $prospect_name
 * @property string|null $prospect_phone
 * @property string|null $prospect_email
 * @property int $created_by
 * @property int|null $advisor_id
 * @property int|null $source_quotation_id
 * @property string|null $notes
 * @property BigDecimal|null $agreed_advance
 * @property int|null $agreed_advance_by
 * @property Carbon|null $agreed_advance_at
 * @property BigDecimal|null $total
 * @property bool $requires_advisor
 * @property array<string, int>|null $pieces_by_line
 * @property BigDecimal|null $advance_percentage
 * @property BigDecimal|null $required_advance
 * @property array<string, string|null>|null $advance_breakdown
 * @property BigDecimal|null $rate_value
 * @property Carbon|null $rate_date
 * @property string|null $rate_origin
 * @property BigDecimal|null $total_bs
 * @property BigDecimal|null $advance_bs
 * @property list<string>|null $advisor_reasons
 * @property array<string, mixed>|null $recipient_snapshot
 * @property array<string, mixed>|null $advisor_snapshot
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_on
 * @property Carbon|null $expired_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read User $creator
 * @property-read User|null $advisor
 * @property-read Collection<int, QuotationLine> $lines
 */
#[Fillable([
    'number', 'status', 'origin', 'customer_id', 'include_contact', 'prospect_name', 'prospect_phone',
    'prospect_email', 'created_by', 'advisor_id', 'source_quotation_id', 'notes', 'agreed_advance',
    'agreed_advance_by', 'agreed_advance_at', 'total', 'requires_advisor', 'pieces_by_line',
    'advance_percentage', 'required_advance', 'advance_breakdown', 'rate_value', 'rate_date', 'rate_origin',
    'total_bs', 'advance_bs', 'advisor_reasons', 'recipient_snapshot', 'advisor_snapshot', 'issued_at',
    'expires_on', 'expired_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    /**
     * The number as the documents show it: `COT-` and at least six digits (`COT-000123`).
     */
    public function displayNumber(): string
    {
        return 'COT-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => QuotationStatus::class,
            'origin' => QuotationOrigin::class,
            'customer_id' => 'integer',
            'include_contact' => 'boolean',
            'created_by' => 'integer',
            'advisor_id' => 'integer',
            'source_quotation_id' => 'integer',
            'agreed_advance' => AsBigDecimal::class.':2',
            'agreed_advance_by' => 'integer',
            'agreed_advance_at' => 'datetime',
            'total' => AsBigDecimal::class.':2',
            'requires_advisor' => 'boolean',
            'pieces_by_line' => 'array',
            'advance_percentage' => AsBigDecimal::class.':2',
            'required_advance' => AsBigDecimal::class.':2',
            'advance_breakdown' => 'array',
            'rate_value' => AsBigDecimal::class.':2',
            'rate_date' => 'date',
            'total_bs' => AsBigDecimal::class.':2',
            'advance_bs' => AsBigDecimal::class.':2',
            'advisor_reasons' => 'array',
            'recipient_snapshot' => 'array',
            'advisor_snapshot' => 'array',
            'issued_at' => 'datetime',
            'expires_on' => 'date',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /**
     * Lines in their position order.
     *
     * @return HasMany<QuotationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('position');
    }
}
