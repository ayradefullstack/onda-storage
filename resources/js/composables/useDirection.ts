import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Writing direction of the active language, taken from the backend's
 * `languages` table (shared as `languages`) — not inferred from a locale
 * code. Follows vue-i18n's locale so it updates on the instant client-side
 * switch, where the Inertia `direction` prop is stale until the next visit.
 */
export function useDirection() {
    const page = usePage();
    const { locale } = useI18n();

    const direction = computed<'ltr' | 'rtl'>(
        () =>
            page.props.languages?.find((l) => l.code === locale.value)
                ?.direction ?? page.props.direction,
    );
    const isRtl = computed(() => direction.value === 'rtl');

    return { direction, isRtl };
}
