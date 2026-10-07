<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppSelect from '@/components/AppSelect.vue';
import type { SelectOption } from '@/components/AppSelect.vue';
import AppTextarea from '@/components/AppTextarea.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { show } from '@/routes/customers';
import type {
    CustomerEditable,
    CustomerFormFields,
    CustomerFormOptions,
    CustomerType,
} from '@/types/customers';
import type { RouteDefinition } from '@/wayfinder';

// Fields and messages only. Every rule (phone and document format, uniqueness, date
// combinations, duplicate phone, type-change cleanup) is applied by the backend, which also
// formats what this form shows back; the form never reformats, detects or decides.
const props = withDefaults(
    defineProps<{
        title: string;
        submitLabel: string;
        cancelHref: string;
        // `store()` or `update(id)` Wayfinder definition.
        action: RouteDefinition<'post'> | RouteDefinition<'put'>;
        options: CustomerFormOptions;
        // Present when editing: the stored customer.
        customer?: CustomerEditable;
    }>(),
    { customer: undefined },
);

const MONTHS = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
];

const DAY_OPTIONS: SelectOption[] = Array.from({ length: 31 }, (_, index) => ({
    value: index + 1,
    label: String(index + 1),
}));

const MONTH_OPTIONS: SelectOption[] = MONTHS.map((label, index) => ({
    value: index + 1,
    label,
}));

const stored = props.customer;

type FormData = CustomerFormFields & { confirm_duplicate_phone: boolean };

const form = useForm<FormData>({
    type: stored?.type ?? '',
    name: stored?.name ?? '',
    document_type: stored?.document_type ?? '',
    document_number: stored?.document_number ?? '',
    phone: stored?.phone ?? '',
    email: stored?.email ?? '',
    birthday_day: stored?.birthday_day ?? '',
    birthday_month: stored?.birthday_month ?? '',
    anniversary_day: stored?.anniversary_day ?? '',
    anniversary_month: stored?.anniversary_month ?? '',
    notes: stored?.notes ?? '',
    contact: {
        name: stored?.contact?.name ?? '',
        position: stored?.contact?.position ?? '',
        phone: stored?.contact?.phone ?? '',
        email: stored?.contact?.email ?? '',
    },
    address: {
        line: stored?.address?.line ?? '',
        city: stored?.address?.city ?? '',
        state: stored?.address?.state ?? '',
        reference: stored?.address?.reference ?? '',
    },
    confirm_duplicate_phone: false,
});

const originalType: CustomerType | null = stored?.type ?? null;

const page = usePage();

const isCompany = computed(() => form.type === 'company');

// Nested errors arrive keyed by their dotted path (`contact.phone`).
function error(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function isBlank(values: Record<string, string>): boolean {
    return Object.values(values).every((value) => value === '');
}

// Document types offered for the selected customer type (the backend validates the choice). A
// stored value that no longer fits stays visible so the backend can reject it on this field.
const documentOptions = computed<SelectOption[]>(() => {
    const allowed =
        form.type === '' ? [] : props.options.documentTypes[form.type];
    const current = Object.values(props.options.documentTypes)
        .flat()
        .find((option) => option.value === form.document_type);

    return current !== undefined && !allowed.includes(current)
        ? [...allowed, current]
        : allowed;
});

const documentFitsType = computed(
    () =>
        form.document_type === '' ||
        form.type === '' ||
        props.options.documentTypes[form.type].some(
            (option) => option.value === form.document_type,
        ),
);

// What the backend removes when an existing company becomes a natural customer (DEC-CLI-15).
const removedByTypeChange = computed<string[]>(() => {
    if (originalType !== 'company' || form.type !== 'natural') {
        return [];
    }

    return [
        ...(form.anniversary_day !== '' || form.anniversary_month !== ''
            ? ['La fecha de aniversario de la empresa']
            : []),
        ...(!isBlank(form.contact) ? ['La persona de contacto'] : []),
    ];
});

// The stored document is never removed (DEC-CLI-15): when it no longer fits, the warning still
// shows even if nothing else is removed.
const documentKeptButUnfit = computed(
    () =>
        originalType === 'company' &&
        form.type === 'natural' &&
        !documentFitsType.value,
);

const showTypeChangeWarning = computed(
    () =>
        removedByTypeChange.value.length > 0 || documentKeptButUnfit.value,
);

// Duplicate-phone warning (E-14): the backend asks for confirmation through an error on
// `confirm_duplicate_phone` and flashes the matching customers.
const phoneMatches = computed(() => page.flash?.duplicatePhoneMatches ?? []);
const showDuplicatePanel = computed(() =>
    Boolean(form.errors.confirm_duplicate_phone),
);

// A confirmation never covers a different number.
watch(
    () => form.phone,
    () => {
        form.confirm_duplicate_phone = false;
        form.clearErrors('confirm_duplicate_phone');
    },
);

// Sections hidden by the type are not sent; empty optional groups travel as `null`.
form.transform((data) => ({
    ...data,
    anniversary_day: data.type === 'company' ? data.anniversary_day : '',
    anniversary_month: data.type === 'company' ? data.anniversary_month : '',
    contact:
        data.type === 'company' && !isBlank(data.contact)
            ? data.contact
            : null,
    address: isBlank(data.address) ? null : data.address,
}));

function submit(): void {
    form.submit(props.action);
}

function submitAnyway(): void {
    form.confirm_duplicate_phone = true;
    submit();
}
</script>

<template>
    <form
        class="mx-auto flex w-full max-w-2xl flex-col gap-space-md"
        novalidate
        @submit.prevent="submit"
    >
        <AppCard>
            <h1 class="font-headline-md text-headline-md text-primary">
                {{ title }}
            </h1>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Datos generales
            </h2>

            <AppSelect
                v-model="form.type"
                label="Tipo de cliente"
                placeholder="Seleccione el tipo"
                :options="options.customerTypes"
                required
                :error="form.errors.type"
            />

            <div
                v-if="showTypeChangeWarning"
                role="alert"
                class="flex flex-col gap-space-xs rounded-lg bg-warning-container p-space-sm font-body-md text-body-md text-on-warning-container"
            >
                <template v-if="removedByTypeChange.length > 0">
                    <p class="font-label-md text-label-md">
                        Al guardar este cambio a persona natural se eliminará:
                    </p>
                    <ul class="list-disc pl-space-lg">
                        <li v-for="item in removedByTypeChange" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </template>
                <p v-if="documentKeptButUnfit">
                    El documento no se elimina: cámbielo o vacíelo antes de
                    guardar.
                </p>
            </div>

            <AppInput
                v-model="form.name"
                :label="isCompany ? 'Razón social' : 'Nombre'"
                autocomplete="off"
                required
                :error="form.errors.name"
            />

            <div class="grid grid-cols-1 gap-space-md md:grid-cols-2">
                <AppSelect
                    v-model="form.document_type"
                    label="Tipo de documento"
                    placeholder="Sin documento"
                    :options="documentOptions"
                    :disabled="form.type === ''"
                    :error="form.errors.document_type"
                />
                <AppInput
                    v-model="form.document_number"
                    label="Número de documento"
                    autocomplete="off"
                    :error="form.errors.document_number"
                />
            </div>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Contacto
            </h2>

            <AppInput
                v-model="form.phone"
                label="Teléfono"
                type="tel"
                inputmode="tel"
                autocomplete="off"
                required
                hint="Celular venezolano, por ejemplo 0414-123-4567."
                :error="form.errors.phone"
            />

            <div
                v-if="showDuplicatePanel"
                role="alert"
                class="flex flex-col gap-space-sm rounded-lg bg-warning-container p-space-sm font-body-md text-body-md text-on-warning-container"
            >
                <p>{{ form.errors.confirm_duplicate_phone }}</p>
                <ul class="flex flex-col gap-space-xs">
                    <li
                        v-for="match in phoneMatches"
                        :key="match.id"
                        class="flex flex-wrap items-center gap-space-sm"
                    >
                        <a
                            :href="show(match.id).url"
                            target="_blank"
                            rel="noopener"
                            class="font-label-md text-label-md underline"
                            >{{ match.name }}</a
                        >
                        <span v-if="match.document">{{ match.document }}</span>
                        <StatusBadge
                            :category="
                                match.status === 'active' ? 'done' : 'neutral'
                            "
                            :label="match.status_label"
                        />
                    </li>
                </ul>
                <AppButton
                    variant="secondary"
                    :disabled="form.processing"
                    @click="submitAnyway"
                >
                    Guardar de todos modos
                </AppButton>
            </div>

            <AppInput
                v-model="form.email"
                label="Correo electrónico"
                type="email"
                autocomplete="off"
                :error="form.errors.email"
            />

            <fieldset class="flex flex-col gap-space-xs">
                <legend
                    class="font-label-md text-label-md text-on-surface-variant"
                >
                    Cumpleaños
                </legend>
                <div class="grid grid-cols-2 gap-space-md">
                    <AppSelect
                        v-model="form.birthday_day"
                        label="Día"
                        placeholder="Día"
                        :options="DAY_OPTIONS"
                        :error="form.errors.birthday_day"
                    />
                    <AppSelect
                        v-model="form.birthday_month"
                        label="Mes"
                        placeholder="Mes"
                        :options="MONTH_OPTIONS"
                        :error="form.errors.birthday_month"
                    />
                </div>
            </fieldset>

            <fieldset v-if="isCompany" class="flex flex-col gap-space-xs">
                <legend
                    class="font-label-md text-label-md text-on-surface-variant"
                >
                    Aniversario de la empresa
                </legend>
                <div class="grid grid-cols-2 gap-space-md">
                    <AppSelect
                        v-model="form.anniversary_day"
                        label="Día"
                        placeholder="Día"
                        :options="DAY_OPTIONS"
                        :error="form.errors.anniversary_day"
                    />
                    <AppSelect
                        v-model="form.anniversary_month"
                        label="Mes"
                        placeholder="Mes"
                        :options="MONTH_OPTIONS"
                        :error="form.errors.anniversary_month"
                    />
                </div>
            </fieldset>
        </AppCard>

        <AppCard v-if="isCompany">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Persona de contacto
            </h2>
            <AppInput
                v-model="form.contact.name"
                label="Nombre"
                autocomplete="off"
                :error="error('contact.name') ?? error('contact')"
            />
            <AppInput
                v-model="form.contact.position"
                label="Cargo"
                autocomplete="off"
                :error="error('contact.position')"
            />
            <AppInput
                v-model="form.contact.phone"
                label="Teléfono"
                type="tel"
                inputmode="tel"
                autocomplete="off"
                :error="error('contact.phone')"
            />
            <AppInput
                v-model="form.contact.email"
                label="Correo electrónico"
                type="email"
                autocomplete="off"
                :error="error('contact.email')"
            />
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Dirección
            </h2>
            <AppInput
                v-model="form.address.line"
                label="Dirección"
                autocomplete="off"
                :error="error('address.line') ?? error('address')"
            />
            <div class="grid grid-cols-1 gap-space-md md:grid-cols-2">
                <AppInput
                    v-model="form.address.city"
                    label="Ciudad"
                    autocomplete="off"
                    :error="error('address.city')"
                />
                <AppSelect
                    v-model="form.address.state"
                    label="Estado"
                    placeholder="Seleccione el estado"
                    :options="options.states"
                    :error="error('address.state')"
                />
            </div>
            <AppInput
                v-model="form.address.reference"
                label="Referencia"
                autocomplete="off"
                :error="error('address.reference')"
            />
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Observaciones
            </h2>
            <AppTextarea
                v-model="form.notes"
                label="Observaciones"
                :error="form.errors.notes"
            />
        </AppCard>

        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton type="submit" :disabled="form.processing">
                {{ submitLabel }}
            </AppButton>
            <AppButton variant="outlined" :href="cancelHref">
                Cancelar
            </AppButton>
        </div>
    </form>
</template>
