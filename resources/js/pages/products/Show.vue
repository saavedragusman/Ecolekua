<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppIcon from '@/components/AppIcon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { FOCUS_RING } from '@/lib/ui';
import {
    activate,
    deactivate,
    destroy,
    edit,
    index,
    structure,
} from '@/routes/products';
import type { ProductCan, ProductDetail } from '@/types/products';

defineOptions({ layout: AppLayout });

// Every value arrives resolved by the backend (labels, descriptive names, effective custom
// color). The buttons only reflect the `can` flags; the backend authorizes every operation.
const props = defineProps<{
    product: ProductDetail;
    can: ProductCan;
}>();

const statusForm = useForm({});
const deleteForm = useForm({});

// Destructive actions submit only after the confirmation dialog (design-system §7.10).
const confirmingDeactivate = ref(false);
const confirmingDelete = ref(false);

function deactivateProduct(): void {
    statusForm.submit(deactivate(props.product.id));
}

function reactivateProduct(): void {
    // Reactivating is reversible and has no side effects: no confirmation.
    statusForm.submit(activate(props.product.id));
}

// A rejection (a combo or history uses the product) comes back as an error flash on this page,
// where "Desactivar" is offered.
function deleteProduct(): void {
    deleteForm.submit(destroy(props.product.id));
}

const combinationColumns: DataTableColumn[] = [
    { key: 'code', label: 'Código' },
    { key: 'name', label: 'Combinación' },
    { key: 'restrictions', label: 'Restricciones' },
    { key: 'included_customizations', label: 'Incluye' },
    { key: 'status', label: 'Estado' },
];

const LABEL = 'font-label-md text-label-md text-on-surface-variant';
const VALUE = 'font-body-md text-body-md text-on-surface';
const H2 = 'font-headline-sm text-headline-sm text-primary';
const EMPTY = 'No registrado';
</script>

<template>
    <Head :title="product.name" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-space-md">
        <AppCard>
            <div
                class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
            >
                <div class="flex items-center gap-space-sm">
                    <Link
                        :href="index().url"
                        aria-label="Volver a productos"
                        title="Volver a productos"
                        :class="[
                            'inline-flex size-11 shrink-0 items-center justify-center rounded-full text-primary transition-colors hover:bg-surface-container-low',
                            FOCUS_RING,
                        ]"
                    >
                        <AppIcon name="arrow_back" />
                    </Link>
                    <h1 class="font-headline-md text-headline-md text-primary">
                        {{ product.name }}
                    </h1>
                </div>
                <StatusBadge
                    :category="product.status === 'active' ? 'done' : 'neutral'"
                    :label="product.status_label"
                />
            </div>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Datos generales</h2>
            <dl class="grid grid-cols-1 gap-space-sm md:grid-cols-2">
                <div class="flex flex-col">
                    <dt :class="LABEL">Categoría</dt>
                    <dd :class="VALUE">{{ product.category }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Línea de negocio</dt>
                    <dd :class="VALUE">{{ product.business_line_label }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Modo de abastecimiento</dt>
                    <dd :class="VALUE">{{ product.supply_mode_label }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Visible en el portal</dt>
                    <dd :class="VALUE">
                        {{ product.portal_visible ? 'Sí' : 'No' }}
                    </dd>
                </div>
                <div class="flex flex-col md:col-span-2">
                    <dt :class="LABEL">Descripción</dt>
                    <dd :class="[VALUE, 'whitespace-pre-line']">
                        {{ product.description ?? 'Sin descripción' }}
                    </dd>
                </div>
            </dl>
            <div v-if="product.admits_custom_color">
                <StatusBadge
                    category="active"
                    icon="palette"
                    label="Admite color personalizado"
                />
            </div>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Atributos</h2>
            <p v-if="product.attributes.length === 0" :class="VALUE">
                El producto no declara atributos.
            </p>
            <dl v-else class="flex flex-col gap-space-sm">
                <div
                    v-for="attribute in product.attributes"
                    :key="attribute.id"
                    class="flex flex-col"
                >
                    <dt :class="LABEL">
                        {{ attribute.name }} · {{ attribute.role_label }}
                    </dt>
                    <dd :class="VALUE">
                        <template v-if="attribute.values.length > 0">
                            {{
                                attribute.values
                                    .map((value) => value.name)
                                    .join(', ')
                            }}
                        </template>
                        <template v-else-if="attribute.follows_fabric">
                            Los colores salen de la tela elegida.
                        </template>
                        <template v-else>Sin valores admitidos</template>
                    </dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Combinaciones</h2>
            <DataTable
                :columns="combinationColumns"
                :rows="product.combinations"
                row-key="id"
                empty-text="El producto no tiene combinaciones."
            >
                <template #cell-code="{ row }">
                    {{ row.code ?? EMPTY }}
                </template>
                <template #cell-restrictions="{ row }">
                    <span v-if="row.restrictions.length === 0">Ninguna</span>
                    <span v-else class="flex flex-col">
                        <span
                            v-for="restriction in row.restrictions"
                            :key="restriction.attribute"
                        >
                            {{ restriction.attribute }}:
                            {{ restriction.values.join(', ') }}
                        </span>
                    </span>
                </template>
                <template #cell-included_customizations="{ row }">
                    {{
                        row.included_customizations.length > 0
                            ? row.included_customizations.join(', ')
                            : 'Nada'
                    }}
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge
                        :category="row.status === 'active' ? 'done' : 'neutral'"
                        :label="row.status_label"
                    />
                </template>
            </DataTable>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Detalles</h2>
            <p v-if="product.detail_locations.length === 0" :class="VALUE">
                El producto no admite detalles.
            </p>
            <ul v-else class="flex flex-col gap-space-xs">
                <li
                    v-for="location in product.detail_locations"
                    :key="location.id"
                    class="flex flex-wrap items-center gap-space-sm"
                >
                    <span :class="VALUE">{{ location.name }}</span>
                    <StatusBadge
                        v-if="location.status === 'inactive'"
                        category="neutral"
                        :label="location.status_label"
                    />
                </li>
            </ul>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Personalizaciones</h2>
            <p v-if="product.customizations.length === 0" :class="VALUE">
                El producto no admite personalizaciones.
            </p>
            <ul v-else class="flex flex-col gap-space-xs">
                <li
                    v-for="service in product.customizations"
                    :key="service.id"
                    class="flex flex-wrap items-center gap-space-sm"
                >
                    <span :class="VALUE">{{ service.name }}</span>
                    <StatusBadge
                        v-if="service.status === 'inactive'"
                        category="neutral"
                        :label="service.status_label"
                    />
                </li>
            </ul>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Stock</h2>
            <p v-if="product.stock.default === null" :class="VALUE">
                El producto no maneja stock mínimo.
            </p>
            <template v-else>
                <dl class="flex flex-col">
                    <dt :class="LABEL">Stock mínimo por defecto</dt>
                    <dd :class="VALUE">{{ product.stock.default }}</dd>
                </dl>
                <p v-if="product.stock.overrides.length === 0" :class="VALUE">
                    Sin mínimos propios por combinación o talla.
                </p>
                <ul v-else class="flex flex-col gap-space-xs">
                    <li
                        v-for="override in product.stock.overrides"
                        :key="`${override.code}-${override.size ?? ''}`"
                        :class="VALUE"
                    >
                        {{ override.code
                        }}<template v-if="override.size !== null">
                            · talla {{ override.size }}</template
                        >: {{ override.minimum }}
                    </li>
                </ul>
            </template>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Imágenes</h2>
            <dl class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt :class="LABEL">Imagen principal</dt>
                    <dd :class="VALUE">
                        {{
                            product.images.has_main
                                ? 'Cargada'
                                : 'Sin imagen principal'
                        }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Plantillas</dt>
                    <dd :class="VALUE">
                        {{
                            product.images.templates.length > 0
                                ? product.images.templates.join(', ')
                                : 'Sin plantillas'
                        }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton v-if="can.update" :href="edit(product.id).url">
                Editar
            </AppButton>
            <AppButton
                v-if="can.update"
                variant="outlined"
                :href="structure(product.id).url"
            >
                Estructura
            </AppButton>
            <AppButton
                v-if="can.deactivate && product.status === 'active'"
                variant="outlined"
                :disabled="statusForm.processing"
                @click="confirmingDeactivate = true"
            >
                Desactivar
            </AppButton>
            <AppButton
                v-if="can.deactivate && product.status === 'inactive'"
                variant="outlined"
                :disabled="statusForm.processing"
                @click="reactivateProduct"
            >
                Reactivar
            </AppButton>
            <AppButton
                v-if="can.delete"
                variant="danger"
                :disabled="deleteForm.processing"
                @click="confirmingDelete = true"
            >
                Eliminar
            </AppButton>
            <AppButton variant="outlined" :href="index().url">
                Volver a productos
            </AppButton>
        </div>

        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar producto"
            message="El producto y sus combinaciones salen de la oferta y no se aceptan en cotizaciones ni pedidos nuevos. Lo que esté en curso continúa su flujo normal."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateProduct"
        />
        <ConfirmDialog
            v-model:open="confirmingDelete"
            title="Eliminar producto"
            message="Se eliminarán el producto, sus atributos, detalles, personalizaciones y combinaciones. Esta acción es irreversible. Si el producto forma parte de un combo o tiene historial no se puede eliminar y deberá desactivarlo."
            confirm-label="Eliminar"
            :processing="deleteForm.processing"
            @confirm="deleteProduct"
        />
    </div>
</template>
