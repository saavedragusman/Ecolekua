<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a customer (CLI-009, design Decision 11). Only `status` changes. An already active
 * customer is a no-op with no audit row (E-40).
 */
class ActivateCustomer
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Customer $customer, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $actor): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if ($customer->status === CustomerStatus::Active) {
                return $customer;
            }

            $customer->forceFill(['status' => CustomerStatus::Active])->save();

            $this->audit->handle(
                AuditAction::CustomerActivated,
                $actor,
                $customer,
                oldValues: ['status' => CustomerStatus::Inactive->value],
                newValues: ['status' => CustomerStatus::Active->value],
            );

            return $customer;
        });
    }
}
