<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { FOCUS_RING } from '@/lib/ui';
import { logout } from '@/routes';
import { edit as passwordEdit } from '@/routes/password';

// Account menu of the ERP header (design Decision 20). Small custom popup: no
// dependency. Esc and outside click close it; focus goes to the first item on
// open and returns to the button on Esc.
const page = usePage();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const button = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLElement | null>(null);
let stopNavigate: VoidFunction | undefined;

const MENU_ID = 'user-menu';

const user = computed(() => page.props.auth?.user ?? null);
const fullName = computed(() =>
    user.value ? `${user.value.first_name} ${user.value.last_name}` : '',
);
const initials = computed(() =>
    user.value
        ? `${user.value.first_name.charAt(0)}${user.value.last_name.charAt(0)}`.toUpperCase()
        : '',
);

const ITEM_CLASSES =
    'flex min-h-11 w-full items-center gap-space-sm rounded-lg px-space-md text-left font-label-lg text-label-lg text-on-surface hover:bg-surface-container-high';

function items(): HTMLElement[] {
    return Array.from(
        menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [],
    );
}

async function openMenu(): Promise<void> {
    open.value = true;
    await nextTick();
    items()[0]?.focus();
}

function closeMenu(returnFocus = false): void {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (returnFocus) {
        button.value?.focus();
    }
}

function toggle(): void {
    if (open.value) {
        closeMenu();
    } else {
        void openMenu();
    }
}

function moveFocus(step: 1 | -1): void {
    const list = items();

    if (list.length === 0) {
        return;
    }

    const current = list.indexOf(document.activeElement as HTMLElement);
    const next = (current + step + list.length) % list.length;
    list[next].focus();
}

function onKeydown(event: KeyboardEvent): void {
    if (!open.value) {
        if (event.target === button.value && event.key === 'ArrowDown') {
            event.preventDefault();
            void openMenu();
        }

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu(true);
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveFocus(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveFocus(-1);
    }
}

function onPointerDown(event: PointerEvent): void {
    if (open.value && !root.value?.contains(event.target as Node)) {
        closeMenu();
    }
}

// Tabbing out of the menu closes it, so it never stays open behind the focus.
function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (open.value && next && !root.value?.contains(next)) {
        closeMenu();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown);
    stopNavigate = router.on('navigate', () => closeMenu());
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown);
    stopNavigate?.();
});
</script>

<template>
    <div
        v-if="user"
        ref="root"
        class="relative"
        @keydown="onKeydown"
        @focusout="onFocusOut"
    >
        <button
            ref="button"
            type="button"
            :aria-label="`Menú de usuario, ${fullName}`"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="MENU_ID"
            class="inline-flex min-h-11 items-center gap-space-sm rounded-full py-space-xs pr-space-sm pl-space-xs text-on-surface hover:bg-surface-container-high"
            :class="FOCUS_RING"
            @click="toggle"
        >
            <span
                aria-hidden="true"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-primary font-label-md text-label-md text-on-primary"
                >{{ initials }}</span
            >
            <span
                class="hidden max-w-40 truncate font-label-md text-label-md sm:inline"
                >{{ fullName }}</span
            >
            <AppIcon name="expand_more" />
        </button>

        <div
            v-show="open"
            :id="MENU_ID"
            ref="menu"
            role="menu"
            :aria-label="`Cuenta de ${fullName}`"
            class="absolute top-full right-0 z-50 mt-space-xs w-60 rounded-lg bg-surface-container-lowest p-space-xs shadow-md"
        >
            <Link
                :href="passwordEdit.url()"
                role="menuitem"
                :class="[ITEM_CLASSES, FOCUS_RING]"
            >
                <AppIcon name="key" />
                <span>Cambiar contraseña</span>
            </Link>
            <Link
                :href="logout.url()"
                method="post"
                as="button"
                role="menuitem"
                :class="[ITEM_CLASSES, FOCUS_RING]"
            >
                <AppIcon name="logout" />
                <span>Cerrar sesión</span>
            </Link>
        </div>
    </div>
</template>
