<?php

namespace App\Support\Quotations;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Support\Customers\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * The recipient search of the quotation editor (COT-013, spec §8 "búsqueda de cliente"): active customers only,
 * matched by name, document number or phone fragment, at most a short page, with what the editor needs to pick
 * one (whether a company has a contact, its advisor).
 */
final class RecipientSearch
{
    /** Customers a search returns at most. */
    public const LIMIT = 20;

    /**
     * @return list<array{id: int, name: string, type: string, document_type: ?string, document_number: ?string, has_contact: bool, advisor_id: ?int}>
     */
    public static function find(string $term): array
    {
        $term = trim($term);
        $phone = $term === '' ? null : PhoneNumber::searchFragment($term);
        $like = '%'.addcslashes($term, '%_\\').'%';

        $customers = Customer::query()
            ->with('contact')
            ->where('status', CustomerStatus::Active->value)
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like, $phone): void {
                // The term is matched as plain text: `%` and `_` are escaped.
                $query->where('name', 'like', $like)->orWhere('document_number', 'like', $like);

                if ($phone !== null) {
                    $query->orWhere('phone', 'like', "%{$phone}%");
                }
            }))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return array_values($customers->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'name' => $customer->name,
            'type' => $customer->type->value,
            'document_type' => $customer->document_type?->value,
            'document_number' => $customer->document_number,
            'has_contact' => $customer->contact !== null,
            'advisor_id' => $customer->advisor_id,
        ])->all());
    }
}
