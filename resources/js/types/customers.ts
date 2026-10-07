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

// Eligible advisor offered by the assignment control; sent only to users with `customers.assign`.
export type AdvisorOption = {
    id: number;
    name: string;
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

// Create and edit forms. The backend validates and normalizes every value; these types only
// describe what the form sends and what the edit page receives.
export type FormOption = {
    value: string;
    label: string;
};

// Sent by `CustomerPresenter::formOptions()`.
export type CustomerFormOptions = {
    customerTypes: FormOption[];
    // Allowed document types per customer type (CLI-003).
    documentTypes: Record<CustomerType, FormOption[]>;
    states: FormOption[];
};

// Customer already stored with the same phone, listed by the duplicate-phone warning (E-14).
export type DuplicatePhoneMatch = {
    id: number;
    name: string;
    document: string | null;
    status: CustomerStatus;
    status_label: string;
};

// Contact person as the edit page receives it (phone as display string).
export type CustomerContactFields = {
    name: string;
    position: string;
    phone: string;
    email: string;
};

export type CustomerAddressFields = {
    line: string;
    city: string;
    // `VenezuelanState` value.
    state: string;
    reference: string;
};

// Day and month stay separate: an empty select is `''`.
export type CustomerFormFields = {
    type: CustomerType | '';
    name: string;
    document_type: string;
    document_number: string;
    phone: string;
    email: string;
    birthday_day: number | '';
    birthday_month: number | '';
    anniversary_day: number | '';
    anniversary_month: number | '';
    notes: string;
    contact: CustomerContactFields;
    address: CustomerAddressFields;
};

// What the edit page receives from `CustomerPresenter::editable()`: nullable columns are `null`,
// contact and address are `null` when the customer has none.
export type CustomerEditable = {
    id: number;
    type: CustomerType;
    name: string;
    document_type: string | null;
    // Canonical form, e.g. `J123456784`.
    document_number: string | null;
    // Display form, e.g. `0414-123-4567`; the backend accepts it back.
    phone: string;
    email: string | null;
    birthday_day: number | null;
    birthday_month: number | null;
    anniversary_day: number | null;
    anniversary_month: number | null;
    notes: string | null;
    contact: {
        name: string;
        position: string | null;
        phone: string;
        email: string | null;
    } | null;
    address: {
        line: string;
        city: string;
        state: string;
        reference: string | null;
    } | null;
};
