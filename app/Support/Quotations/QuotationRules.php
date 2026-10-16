<?php

namespace App\Support\Quotations;

use App\Rules\VenezuelanPhone;
use Illuminate\Validation\Validator;

/**
 * Structure rules of a quotation's recipient and advisor (COT-013, E-68), shared by the create and the draft
 * update requests. A quotation is for an active customer or for a prospect, never both; a prospect has a name
 * and a mobile Venezuelan phone stored as E.164. Whether the customer is active and the advisor eligible is
 * checked by the Action, against the database.
 */
final class QuotationRules
{
    public const PROSPECT_NAME_MAX_LENGTH = 150;

    /**
     * @return array<string, list<mixed>>
     */
    public static function recipient(): array
    {
        return [
            'customer_id' => ['nullable', 'integer'],
            'include_contact' => ['sometimes', 'boolean'],
            'prospect_name' => ['nullable', 'required_with:prospect_phone,prospect_email', 'string', 'max:'.self::PROSPECT_NAME_MAX_LENGTH],
            'prospect_phone' => ['nullable', 'required_with:prospect_name,prospect_email', 'string', 'max:30', new VenezuelanPhone(allowLandline: false)],
            'prospect_email' => ['nullable', 'email', 'max:255'],
            'advisor_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * The catalog rows a saved line references must exist, so a stored draft never points nowhere.
     *
     * @return array<string, list<string>>
     */
    public static function lineReferences(): array
    {
        return [
            'lines.*.id' => ['distinct'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.combo_id' => ['nullable', 'integer', 'exists:combos,id'],
            'lines.*.rows.*.size_value_id' => ['nullable', 'integer', 'exists:attribute_values,id'],
            'lines.*.customizations.*.service_product_id' => ['required', 'integer', 'exists:products,id'],
        ];
    }

    /**
     * Size rows of a product line (COT-014, E-35): at least one; each size once; either rows by size or a single
     * row without size, never both.
     */
    public static function validateSizeRows(Validator $validator): void
    {
        $lines = $validator->getData()['lines'] ?? null;

        foreach (is_array($lines) ? $lines : [] as $i => $line) {
            if (! is_array($line) || ($line['kind'] ?? null) !== 'product' || ! is_array($line['rows'] ?? null)) {
                continue;
            }

            $sizes = array_map(fn (mixed $row): mixed => is_array($row) ? ($row['size_value_id'] ?? null) : null, $line['rows']);
            $withSize = array_values(array_filter($sizes, fn (mixed $size): bool => $size !== null));
            $withoutSize = count($sizes) - count($withSize);

            $message = match (true) {
                $sizes === [] => 'quotation_rows_required',
                $withSize !== [] && $withoutSize > 0, $withoutSize > 1 => 'quotation_rows_mixed',
                count(array_unique($withSize)) !== count($withSize) => 'quotation_rows_size_repeated',
                default => null,
            };

            if ($message !== null) {
                $validator->errors()->add("lines.{$i}.rows", __("validation.{$message}"));
            }
        }
    }

    /**
     * Exactly one recipient: a customer or a prospect (the database CHECK backs it).
     */
    public static function validateOneRecipient(Validator $validator): void
    {
        $data = $validator->getData();
        $customer = ($data['customer_id'] ?? null) !== null;
        $prospect = array_filter([$data['prospect_name'] ?? null, $data['prospect_phone'] ?? null, $data['prospect_email'] ?? null], fn (mixed $value): bool => $value !== null && $value !== '') !== [];

        if (! $customer && ! $prospect) {
            $validator->errors()->add('customer_id', __('validation.quotation_recipient_required'));
        } elseif ($customer && $prospect) {
            $validator->errors()->add('customer_id', __('validation.quotation_recipient_both'));
        }
    }
}
