<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/customers';
import type { CustomerDetail } from '@/types/customers';

defineOptions({ layout: AppLayout });

// Read-only page (CLI-013). Every value arrives formatted by the backend. The action buttons
// (edit, deactivate, delete, assign advisor) arrive in later phases.
defineProps<{
    customer: CustomerDetail;
}>();

const LABEL = 'font-label-md text-label-md text-on-surface-variant';
const VALUE = 'font-body-md text-body-md text-on-surface';
const EMPTY = 'No registrado';
</script>

<template>
    <Head :title="customer.name" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-space-md">
        <AppCard>
            <div
                class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
            >
                <h1 class="font-headline-md text-headline-md text-primary">
                    {{ customer.name }}
                </h1>
                <StatusBadge
                    :category="customer.status === 'active' ? 'done' : 'neutral'"
                    :label="customer.status_label"
                />
            </div>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Datos generales
            </h2>
            <dl class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt :class="LABEL">Tipo de cliente</dt>
                    <dd :class="VALUE">{{ customer.type_label }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">
                        {{ customer.document_type_label ?? 'Documento' }}
                    </dt>
                    <dd :class="VALUE">{{ customer.document ?? EMPTY }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Fecha de registro</dt>
                    <dd :class="VALUE">{{ customer.created_at ?? EMPTY }}</dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Contacto
            </h2>
            <dl class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt :class="LABEL">Teléfono</dt>
                    <dd :class="VALUE">{{ customer.phone }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Correo electrónico</dt>
                    <dd :class="VALUE">{{ customer.email ?? EMPTY }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Cumpleaños</dt>
                    <dd :class="VALUE">{{ customer.birthday ?? EMPTY }}</dd>
                </div>
                <div v-if="customer.type === 'company'" class="flex flex-col">
                    <dt :class="LABEL">Aniversario</dt>
                    <dd :class="VALUE">{{ customer.anniversary ?? EMPTY }}</dd>
                </div>
            </dl>
        </AppCard>

        <AppCard v-if="customer.type === 'company'">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Persona de contacto
            </h2>
            <p v-if="customer.contact === null" :class="VALUE">
                {{ EMPTY }}
            </p>
            <dl v-else class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt :class="LABEL">Nombre</dt>
                    <dd :class="VALUE">{{ customer.contact.name }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Cargo</dt>
                    <dd :class="VALUE">
                        {{ customer.contact.position ?? EMPTY }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Teléfono</dt>
                    <dd :class="VALUE">{{ customer.contact.phone }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Correo electrónico</dt>
                    <dd :class="VALUE">
                        {{ customer.contact.email ?? EMPTY }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Dirección
            </h2>
            <p v-if="customer.address === null" :class="VALUE">{{ EMPTY }}</p>
            <dl v-else class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt :class="LABEL">Dirección</dt>
                    <dd :class="VALUE">{{ customer.address.line }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Ciudad</dt>
                    <dd :class="VALUE">{{ customer.address.city }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Estado</dt>
                    <dd :class="VALUE">{{ customer.address.state_label }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Referencia</dt>
                    <dd :class="VALUE">
                        {{ customer.address.reference ?? EMPTY }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Observaciones
            </h2>
            <p :class="[VALUE, 'whitespace-pre-line']">
                {{ customer.notes ?? 'Sin observaciones' }}
            </p>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Asesora
            </h2>
            <p v-if="customer.advisor === null" :class="VALUE">Sin asesora</p>
            <div v-else class="flex flex-wrap items-center gap-space-sm">
                <span :class="VALUE">{{ customer.advisor.name }}</span>
                <StatusBadge
                    v-if="!customer.advisor.available"
                    category="pending"
                    label="Asesora no disponible"
                />
            </div>
        </AppCard>

        <!-- Quotation (004) and order (006) history sections are added here by those specs. -->

        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton variant="outlined" :href="index().url">
                Volver a clientes
            </AppButton>
        </div>
    </div>
</template>
