import { nextTick } from 'vue';

// The first field marked invalid by a form component, or else a non-field error box.
const FIRST_ERROR = '[aria-invalid="true"], [role="alert"]';

// Visit options for saving a long form. On success the page starts at the top, where
// the layout shows the flash message. On validation errors it keeps its place and
// brings the first error into view, focusing it when it is a field. Honors the
// reduced-motion preference (design-system §8).
export function useSaveScroll() {
    async function revealFirstError(): Promise<void> {
        await nextTick();

        const reduceMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const behavior: ScrollBehavior = reduceMotion ? 'auto' : 'smooth';
        const target = document.querySelector<HTMLElement>(FIRST_ERROR);

        if (target === null) {
            window.scrollTo({ top: 0, behavior });

            return;
        }

        target.scrollIntoView({ behavior, block: 'center' });

        if (target.matches('[aria-invalid="true"]')) {
            // The scroll above is the visible movement: do not let focus() jump the page too.
            target.focus({ preventScroll: true });
        }
    }

    return {
        saveOptions: {
            preserveScroll: 'errors' as const,
            onError: () => {
                void revealFirstError();
            },
        },
    };
}
