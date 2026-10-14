<?php

namespace App\Enums;

/**
 * Lifecycle status of a quotation (COT-015). The backed value is stored in `quotations.status`.
 * `Approved` exists so the column and the badges are complete; no 004 code path sets it (spec §10, `006`).
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Issued => 'Emitida',
            self::Expired => 'Vencida',
            self::Cancelled => 'Anulada',
            self::Approved => 'Aprobada',
        };
    }

    /**
     * Category of the `StatusBadge` component (COT-015).
     */
    public function badge(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'neutral',
            self::Issued => 'active',
            self::Expired => 'pending',
            self::Approved => 'done',
        };
    }
}
