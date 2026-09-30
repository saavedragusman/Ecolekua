import { readonly, ref } from 'vue';

export type Appearance = 'light' | 'dark';

// Same key read by the inline no-flash script in app.blade.php (UI-06).
const STORAGE_KEY = 'appearance';

const appearance = ref<Appearance>('light');
let initialized = false;

function applyToDocument(value: Appearance): void {
    document.documentElement.classList.toggle('dark', value === 'dark');
}

function persist(value: Appearance): void {
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // Storage unavailable (private mode, blocked): the choice still
        // applies for this page view but is not remembered.
    }
}

function initialize(): void {
    if (initialized || typeof document === 'undefined') {
        return;
    }

    initialized = true;
    // The inline script in the layout head already applied the stored theme
    // before first paint; mirror the resulting state instead of re-reading.
    appearance.value = document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
}

export function useAppearance() {
    initialize();

    function setAppearance(value: Appearance): void {
        appearance.value = value;
        applyToDocument(value);
        persist(value);
    }

    function toggleAppearance(): void {
        setAppearance(appearance.value === 'dark' ? 'light' : 'dark');
    }

    return {
        appearance: readonly(appearance),
        setAppearance,
        toggleAppearance,
    };
}
