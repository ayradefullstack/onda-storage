<script setup lang="ts">
import { PlusCircle, Search } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationPopover from '@/components/dashboard/NotificationPopover.vue';
import LanguageSwitcher from '@/components/public/LanguageSwitcher.vue';
import ThemeToggle from '@/components/public/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useDashboardModal } from '@/composables/useDashboardModal';
import type { BreadcrumbItem } from '@/types';

const { t } = useI18n();
const { openDeposit, openSearch } = useDashboardModal();

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const handleSearchClick = () => {
    openSearch();
};

const handleDepositClick = () => {
    openDeposit('music');
};
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between gap-2 border-b border-border/80 bg-background/80 px-4 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6"
    >
        <!-- Start: Sidebar Toggle & Breadcrumbs -->
        <div class="flex min-w-0 items-center gap-2 sm:gap-3">
            <SidebarTrigger
                class="-ms-1 text-foreground/80 hover:text-foreground"
            />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs
                    :breadcrumbs="breadcrumbs"
                    class="hidden sm:flex"
                />
            </template>
        </div>

        <!-- End: Quick Actions, Search, Notifications, Language, Theme -->
        <div class="flex items-center gap-1.5 sm:gap-2.5">
            <!-- Quick Search Bar / Button -->
            <Button
                variant="outline"
                size="sm"
                class="hidden md:flex items-center gap-2 border-border/80 bg-background/60 text-xs text-muted-foreground hover:text-foreground"
                @click="handleSearchClick"
            >
                <Search :stroke-width="1.75" class="size-3.5" />
                <span class="inline-block max-w-[140px] truncate">{{
                    t('nav.search')
                }}</span>
                <kbd
                    class="pointer-events-none ms-2 inline-flex h-4.5 items-center gap-0.5 rounded border border-border bg-muted/60 px-1 font-mono text-[10px] font-medium text-muted-foreground select-none"
                >
                    <span class="text-xs">⌘</span>K
                </kbd>
            </Button>

            <!-- Quick Deposit Button -->
            <Button
                variant="default"
                size="sm"
                class="gap-1.5 text-xs font-semibold shadow-xs"
                @click="handleDepositClick"
            >
                <PlusCircle :stroke-width="1.75" class="size-3.5" />
                <span class="hidden sm:inline">{{
                    t('dashboard.newDepositBtn')
                }}</span>
            </Button>

            <div class="mx-0.5 hidden h-4 w-px bg-border/80 sm:block" />

            <!-- Notification Center -->
            <NotificationPopover />

            <!-- Language Switcher -->
            <LanguageSwitcher />

            <!-- Theme Toggle -->
            <ThemeToggle />
        </div>
    </header>
</template>
