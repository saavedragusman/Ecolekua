<?php

namespace App\Support\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Support\Time\OperatingTime;
use Illuminate\Support\Collection;

/**
 * Read models of a customer for the Inertia pages. Every display string (document, phone, dates,
 * labels) and the advisor availability are produced here, so the frontend only renders what the
 * backend returns (AGENTS.md section 7.1).
 */
final class CustomerPresenter
{
    private const MONTHS = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * Ids, among the advisors of the given customers, that are still eligible advisors (active user
     * holding `customers.portfolio`): one query per page (design Decision 12). "Asesora no
     * disponible" is computed on every read and never stored (DEC-CLI-29).
     *
     * @param  Collection<int, Customer>  $customers
     * @return list<int>
     */
    public static function availableAdvisorIds(Collection $customers): array
    {
        $advisorIds = $customers->pluck('advisor_id')->filter()->unique()->values()->all();

        if ($advisorIds === []) {
            return [];
        }

        return array_values(User::eligibleAdvisors()
            ->whereIn('users.id', $advisorIds)
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * One row of the customers list (design Decision 13). Expects `advisor` to be loaded.
     *
     * @param  list<int>  $availableAdvisorIds  from availableAdvisorIds()
     * @return array<string, mixed>
     */
    public static function listRow(Customer $customer, array $availableAdvisorIds): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'type' => $customer->type->value,
            'type_label' => $customer->type->label(),
            'document' => self::document($customer),
            'phone' => PhoneNumber::parse($customer->phone)->display(),
            'status' => $customer->status->value,
            'status_label' => $customer->status->label(),
            'advisor' => self::advisor($customer, $availableAdvisorIds),
        ];
    }

    /**
     * The customer page (CLI-013). Expects `contact`, `address` and `advisor` to be loaded.
     *
     * @param  list<int>  $availableAdvisorIds  from availableAdvisorIds()
     * @return array<string, mixed>
     */
    public static function detail(Customer $customer, array $availableAdvisorIds): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'type' => $customer->type->value,
            'type_label' => $customer->type->label(),
            'status' => $customer->status->value,
            'status_label' => $customer->status->label(),
            'document_type_label' => $customer->document_type?->label(),
            'document' => self::document($customer),
            'created_at' => $customer->created_at === null ? null : OperatingTime::format($customer->created_at),
            'phone' => PhoneNumber::parse($customer->phone)->display(),
            'email' => $customer->email,
            'birthday' => self::dayAndMonth($customer->birthday_day, $customer->birthday_month),
            'anniversary' => self::dayAndMonth($customer->anniversary_day, $customer->anniversary_month),
            'contact' => $customer->contact === null ? null : [
                'name' => $customer->contact->name,
                'position' => $customer->contact->position,
                'phone' => PhoneNumber::parse($customer->contact->phone)->display(),
                'email' => $customer->contact->email,
            ],
            'address' => $customer->address === null ? null : [
                'line' => $customer->address->line,
                'city' => $customer->address->city,
                'state_label' => $customer->address->state->label(),
                'reference' => $customer->address->reference,
            ],
            'notes' => $customer->notes,
            'advisor' => self::advisor($customer, $availableAdvisorIds),
        ];
    }

    private static function document(Customer $customer): ?string
    {
        if ($customer->document_type === null || $customer->document_number === null) {
            return null;
        }

        return DocumentNumber::display($customer->document_type, $customer->document_number);
    }

    /**
     * @param  list<int>  $availableAdvisorIds
     * @return array{id: int, name: string, available: bool}|null
     */
    private static function advisor(Customer $customer, array $availableAdvisorIds): ?array
    {
        if ($customer->advisor === null) {
            return null;
        }

        return [
            'id' => $customer->advisor->id,
            'name' => $customer->advisor->fullName(),
            'available' => in_array($customer->advisor->id, $availableAdvisorIds, true),
        ];
    }

    /**
     * Commemorative dates carry no year (CLI-017): `29 de febrero`.
     */
    private static function dayAndMonth(?int $day, ?int $month): ?string
    {
        if ($day === null || $month === null) {
            return null;
        }

        return $day.' de '.self::MONTHS[$month];
    }
}
