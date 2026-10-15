<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\BusinessLine;
use App\Enums\LineKind;
use App\Enums\PriceSchemeType;

/**
 * What the piece count needs to know about one quotation line (COT-008, design Decision 15): its kind,
 * the product and whether it is a service, the business line, the type of the product's scheme (null when
 * the product has none) and its rows with their quantities. A row with a selection error is marked as not
 * valid. Customizations added to a garment are not part of these facts because they never add pieces
 * (DEC-COT-13).
 */
final readonly class LineFacts
{
    /**
     * @param  list<array{quantity: int, valid: bool}>  $rows
     */
    public function __construct(
        public LineKind $kind,
        public ?int $productId,
        public ?BusinessLine $businessLine,
        public bool $isService,
        public ?PriceSchemeType $schemeType,
        public array $rows,
    ) {}

    /** Sum of the quantities of the rows without a selection error (note N-2). */
    public function validQuantity(): int
    {
        $quantity = 0;

        foreach ($this->rows as $row) {
            if ($row['valid']) {
                $quantity += $row['quantity'];
            }
        }

        return $quantity;
    }
}
