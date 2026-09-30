import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// UI convenience only (FND-019): decides what to show. The backend authorizes
// every operation with Policies.
export function usePermissions() {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.permissions ?? []);

    function can(permission: string): boolean {
        return permissions.value.includes(permission);
    }

    return { can };
}
