<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\CommercialParametersFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The single row (`id = 1`, inserted by the migration) with the advance percentage and the validity
 * of quotations (COT-006, design Decision 13).
 *
 * @property int $id
 * @property BigDecimal $advance_percentage
 * @property int $validity_days
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $updatedBy
 */
#[Fillable(['advance_percentage', 'validity_days', 'updated_by'])]
class CommercialParameters extends Model
{
    /** @use HasFactory<CommercialParametersFactory> */
    use HasFactory;

    protected $table = 'commercial_parameters';

    /** The id of the single row, inserted by its migration. */
    public const ROW_ID = 1;

    public $incrementing = false;

    /**
     * The parameters in force.
     */
    public static function current(): self
    {
        return self::query()->findOrFail(self::ROW_ID);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'advance_percentage' => AsBigDecimal::class.':2',
            'validity_days' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
