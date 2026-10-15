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
