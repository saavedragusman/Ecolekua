// Shapes sent by `App\Http\Controllers\Customers\CustomerController` through
// `App\Support\Customers\CustomerPresenter` (read-only DTOs). Every value is already
// formatted by the backend: the UI only renders it.
import type { Paginated } from '@/types/users';

export type CustomerStatus = 'active' | 'inactive';
export type CustomerType = 'natural' | 'company';

// Views of the list resolved by the backend (design Decision 13).
export type CustomerStatusFilter = CustomerStatus | 'all';

export type CustomerStatusCounts = Record<CustomerStatusFilter, number>;

// `available` is false when the advisor is inactive or lost `customers.portfolio`
// (DEC-CLI-29): the assignment is kept, the UI only flags it.
export type CustomerAdvisor = {
    id: number;
    name: string;
    available: boolean;
};

export type CustomerListRow = {
    id: number;
    name: string;
    type: CustomerType;
    type_label: string;
    document: string | null;
    phone: string;
    status: CustomerStatus;
    status_label: string;
    advisor: CustomerAdvisor | null;
};

export type CustomerListPage = Paginated<CustomerListRow>;

export type CustomerContactPerson = {
    name: string;
    position: string | null;
    phone: string;
    email: string | null;
};

export type CustomerAddress = {
    line: string;
    city: string;
    state_label: string;
    reference: string | null;
};

export type CustomerDetail = {
    id: number;
    name: string;
    type: CustomerType;
    type_label: string;
    status: CustomerStatus;
    status_label: string;
    document_type_label: string | null;
    document: string | null;
    created_at: string | null;
    phone: string;
    email: string | null;
    // Day and month without year, e.g. "29 de febrero".
    birthday: string | null;
    anniversary: string | null;
    contact: CustomerContactPerson | null;
    address: CustomerAddress | null;
    notes: string | null;
    advisor: CustomerAdvisor | null;
};
