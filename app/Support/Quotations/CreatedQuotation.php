<?php

namespace App\Support\Quotations;

use App\Models\Quotation;

/**
 * A new draft and whether the customer's advisor was skipped because it is inactive or lacks
 * `customers.portfolio`, so the screen shows "asesora del cliente no disponible" (DEC-COT-63, E-74).
 */
final readonly class CreatedQuotation
{
    public function __construct(
        public Quotation $quotation,
        public bool $customerAdvisorUnavailable,
    ) {}
}
