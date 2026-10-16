<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\QuotationOrigin;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Customers\PhoneNumber;
use App\Support\Quotations\CreatedQuotation;
use App\Support\Quotations\QuotationNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft quotation (COT-013, design Decision 19) for an active customer or for a prospect, with the
 * next number of the sequence and origin "asesora". The advisor is the explicit one when sent (an active user
 * with `customers.portfolio`), else the customer's advisor when it is available (DEC-COT-63), else the creator
 * when eligible, else none; the result says when the customer's advisor was skipped. Lines
 * are added by saving the draft. The creation is audited with number, recipient and advisor (E-32).
 */
class CreateQuotation
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see QuotationRules::recipient())
     *
     * @throws ValidationException when the customer is missing or inactive, the contact cannot be included or
     *                             the advisor is not eligible
     */
    public function handle(array $data, User $actor, ?AuditOrigin $origin = null): CreatedQuotation
    {
        return DB::transaction(function () use ($data, $actor, $origin): CreatedQuotation {
            $customer = isset($data['customer_id']) ? $this->activeCustomer((int) $data['customer_id']) : null;
            $includeContact = (bool) ($data['include_contact'] ?? false);

            if ($includeContact && ($customer === null || $customer->type !== CustomerType::Company || $customer->contact === null)) {
                throw ValidationException::withMessages(['include_contact' => __('validation.quotation_include_contact_invalid')]);
            }

            $customerAdvisorUnavailable = $customer?->advisor_id !== null && ! $this->isEligible($customer->advisor_id);
            $advisorId = $this->advisorId($data['advisor_id'] ?? null, $customerAdvisorUnavailable ? null : $customer, $actor);

            $quotation = Quotation::query()->create([
                'number' => QuotationNumber::next(),
                'status' => QuotationStatus::Draft,
                'origin' => QuotationOrigin::Advisor,
                'customer_id' => $customer?->id,
                'include_contact' => $includeContact,
                'prospect_name' => $customer === null ? $data['prospect_name'] : null,
                'prospect_phone' => $customer === null ? PhoneNumber::parse((string) $data['prospect_phone'])->e164() : null,
                'prospect_email' => $customer === null ? ($data['prospect_email'] ?? null) : null,
                'created_by' => $actor->id,
                'advisor_id' => $advisorId,
            ]);

            $this->audit->handle(AuditAction::QuotationCreated, $actor, $quotation, newValues: [
                'number' => $quotation->displayNumber(),
                'customer_id' => $quotation->customer_id,
                'include_contact' => $quotation->include_contact,
                'prospect_name' => $quotation->prospect_name,
                'prospect_phone' => $quotation->prospect_phone,
                'prospect_email' => $quotation->prospect_email,
                'advisor_id' => $quotation->advisor_id,
            ], origin: $origin);

            return new CreatedQuotation($quotation, $customerAdvisorUnavailable);
        });
    }

    private function activeCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->with('contact')->find($customerId);

        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => __('validation.quotation_customer_missing')]);
        }

        if ($customer->status !== CustomerStatus::Active) {
            throw ValidationException::withMessages(['customer_id' => __('validation.quotation_customer_inactive')]);
        }

        return $customer;
    }

    /**
     * The advisor of a new quotation (COT-013, DEC-COT-63): the explicit one when sent, which must be eligible;
     * else the customer's advisor when available (the caller passes no customer when it is not); else the
     * creator when eligible; else none.
     */
    private function advisorId(mixed $explicit, ?Customer $customer, User $actor): ?int
    {
        if ($explicit !== null) {
            return $this->isEligible((int) $explicit)
                ? (int) $explicit
                : throw ValidationException::withMessages(['advisor_id' => __('validation.quotation_advisor_ineligible')]);
        }

        return $customer->advisor_id ?? ($actor->isEligibleAdvisor() ? $actor->id : null);
    }

    /** An active user with `customers.portfolio` (002 `eligibleAdvisors`). */
    private function isEligible(int $userId): bool
    {
        return User::query()->eligibleAdvisors()->whereKey($userId)->exists();
    }
}
