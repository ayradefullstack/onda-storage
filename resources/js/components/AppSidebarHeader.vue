<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationPopover from '@/components/dashboard/NotificationPopover.vue';
import LanguageSwitcher from '@/components/public/LanguageSwitcher.vue';
import ThemeToggle from '@/components/public/ThemeToggle.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useScrolled } from '@/composables/useScrolled';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

// A soft shadow once the page has moved under the sticky header.
const scrolled = useScrolled();
</script>

<template>
    <header
        :data-scrolled="scrolled"
        class="motion-header-shadow sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between gap-2 border-b border-border/80 bg-background/80 px-4 backdrop-blur-md group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6"
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

        <!-- End: Notifications, Language, Theme -->
        <div class="flex items-center gap-1.5 sm:gap-2.5">
            <!-- Notification Center -->
            <NotificationPopover />

            <!-- Language Switcher -->
            <LanguageSwitcher />

            <!-- Theme Toggle -->
            <ThemeToggle />
        </div>
    </header>
</template>
