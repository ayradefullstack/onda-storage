<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Globe } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    applyHtmlDirLang,
    persistLocaleCookie,
    withLocaleSegment,
} from '@/i18n';
import type { SupportedLocale } from '@/i18n';
import locale from '@/routes/locale';

const { t, locale: activeLocale } = useI18n();
const page = usePage();

const languages: { code: SupportedLocale; key: string }[] = [
    { code: 'ar', key: 'common.language.ar' },
    { code: 'fr', key: 'common.language.fr' },
    { code: 'en', key: 'common.language.en' },
];

/**
 * Every UI string is already bundled client-side (vue-i18n loads ar/fr/en up
 * front), so switching locale needs no server round trip at all: flip the
 * reactive locale, update <html dir/lang>, and persist the choice to the
 * same cookie SetLocale reads on the next full load. A round trip would go
 * through Inertia and remount the current page component, wiping whatever
 * the user was doing (e.g. a half-filled login form) — this keeps it intact.
 *
 * `href` is kept as a working fallback (middle-click/new tab, JS disabled):
 * it still hits `locale.switch`, which does the same job server-side and
 * redirects back to the current page.
 */
function switchLocale(event: MouseEvent, code: SupportedLocale) {
    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }

    event.preventDefault();

    if (code === activeLocale.value) {
        return;
    }

    activeLocale.value = code;
    applyHtmlDirLang(code);
    persistLocaleCookie(code);

    const target = withLocaleSegment(page.url, code);
    const current =
        window.location.pathname +
        window.location.search +
        window.location.hash;

    if (target !== current) {
        window.history.replaceState(window.history.state, '', target);
    }
}

function switchLocaleHref(code: SupportedLocale): string {
    return locale.switch.url(
        { locale: code },
        { query: { redirect: page.url } },
    );
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="t('common.languageSwitcher')"
        >
            <Globe class="size-4" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="lang in languages"
                :key="lang.code"
                as-child
            >
                <a
                    :href="switchLocaleHref(lang.code)"
                    :aria-current="
                        activeLocale === lang.code ? 'page' : undefined
                    "
                    class="w-full aria-[current=page]:font-semibold aria-[current=page]:text-seal"
                    @click="switchLocale($event, lang.code)"
                >
                    {{ t(lang.key) }}
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
