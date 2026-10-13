import { nextTick } from 'vue';

// A template ref to a plain <form> or to a component whose root element is the form.
type FormTarget = { readonly value: unknown };

const FIRST_FIELD =
    'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';

// Brings an inline form into view and focuses its first field, so pressing "Editar"
// on a long list does not leave the form out of sight. Honors the reduced-motion
// preference (design-system §8). Call it right after opening the form: it waits
// for the next render before looking for the element.
export function useScrollToForm(target: FormTarget) {
    async function scrollToForm(): Promise<void> {
        await nextTick();

        const value = target.value;
        const candidate =
            value !== null && typeof value === 'object' && '$el' in value
                ? value.$el
                : value;

        if (!(candidate instanceof HTMLElement)) {
            return;
        }

        const element = candidate;

        const reduceMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        element.scrollIntoView({
            behavior: reduceMotion ? 'auto' : 'smooth',
            block: 'start',
        });
        // The scroll above is the visible movement: do not let focus() jump the page too.
        element.querySelector<HTMLElement>(FIRST_FIELD)?.focus({
            preventScroll: true,
        });
    }

    return { scrollToForm };
}
