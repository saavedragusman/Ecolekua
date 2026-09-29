// Shared focus-ring classes (design-system §8.1). Kept as complete literal
// strings so Tailwind's source scan generates every utility.
export const FOCUS_RING =
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface';

// For controls placed over dark brand backgrounds (primary, primary-container).
export const FOCUS_RING_ON_DARK =
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-container focus-visible:ring-offset-2 focus-visible:ring-offset-primary-container';
