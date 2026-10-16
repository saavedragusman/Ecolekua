<?php

namespace App\Enums;

/**
 * Delivery state of a quotation email (design Decision 21). The backed value is stored in
 * `quotation_email_deliveries.status`.
 */
enum EmailDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cola',
            self::Sent => 'Enviado',
            self::Failed => 'Fallido',
        };
    }
}
