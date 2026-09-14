<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Files,
    FolderOpen,
    Headphones,
    LayoutGrid,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { toUrl } from '@/lib/utils';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminAuthorsIndex } from '@/routes/admin/authors';
import { index as adminWorksIndex } from '@/routes/admin/works';
import { dashboard as authorDashboard } from '@/routes/author';
import { index as worksIndex } from '@/routes/works';

const { t, locale } = useI18n();
const page = usePage();
const { isMobile, state } = useSidebar();

const isRtl = computed(() => locale.value === 'ar');
const roles = computed(() => page.props.auth.roles);
const isAuthor = computed(() => roles.value.includes('author'));
const isAdmin = computed(() => roles.value.includes('admin'));

function isActive(href: Parameters<typeof toUrl>[0]): boolean {
    return page.url === toUrl(href) || page.url.startsWith(toUrl(href) + '/');
}

const mainNavItems = computed(() => [
    {
        title: t('sidebar.nav.overview'),
        href: authorDashboard(),
        icon: LayoutGrid,
        active: isActive(authorDashboard()),
    },
    {
        title: t('sidebar.nav.works'),
        href: worksIndex(),
        icon: Files,
        active: isActive(worksIndex()),
    },
]);

const adminNavItems = computed(() => [
    {
        title: t('sidebar.nav.overview'),
        href: adminDashboard(),
        icon: LayoutGrid,
        active: isActive(adminDashboard()),
    },
    {
        title: t('admin.authors.title'),
        href: adminAuthorsIndex(),
        icon: Users,
        active: isActive(adminAuthorsIndex()),
    },
    {
        title: t('admin.works.title'),
        href: adminWorksIndex(),
        icon: FolderOpen,
        active: isActive(adminWorksIndex()),
    },
]);

// A user holding both roles gets both sections rather than an arbitrary
// single choice — the header logo still needs one link, so it favors
// author (the higher-traffic, day-to-day area) when both are present.
const homeHref = computed(() =>
    isAuthor.value ? authorDashboard() : adminDashboard(),
);
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        :side="isRtl ? 'right' : 'left'"
        class="border-sidebar-border/70"
    >
        <SidebarHeader class="border-b border-sidebar-border/60 pb-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="hover:bg-sidebar-accent/60"
                    >
                        <Link :href="homeHref" class="flex items-center gap-3">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="py-2">
            <template v-if="isAuthor">
                <!-- Workspace Navigation Group -->
                <SidebarGroup>
                    <SidebarGroupLabel
                        class="text-start text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        {{ t('sidebar.nav.main') }}
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        <SidebarMenuItem
                            v-for="item in mainNavItems"
                            :key="item.title"
                        >
                            <SidebarMenuButton
                                as-child
                                :is-active="item.active"
                                :tooltip="item.title"
                                class="group relative rounded-lg font-medium transition-all data-[active=true]:bg-onda-blue-600/10 data-[active=true]:font-semibold data-[active=true]:text-onda-blue-700 dark:data-[active=true]:bg-onda-blue-500/20 dark:data-[active=true]:text-onda-blue-400"
                            >
                                <Link
                                    :href="item.href"
                                    class="flex w-full items-center gap-3 text-start"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-4 shrink-0 transition-transform group-hover:scale-110"
                                    />
                                    <span class="truncate">{{
                                        item.title
                                    }}</span>
                                    <span
                                        v-if="item.badge"
                                        class="ms-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-onda-blue-600/10 px-1.5 font-mono text-[10px] font-bold text-onda-blue-700 dark:bg-onda-teal-500/20 dark:text-onda-teal-300"
                                    >
                                        {{ item.badge }}
                                    </span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </template>

            <!-- Admin section — clearly separated from the author workspace
                 above when a user holds both roles, not merged into it. -->
            <SidebarGroup v-if="isAdmin" class="mt-2">
                <SidebarGroupLabel
                    class="text-start text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    {{ t('sidebar.nav.adminSection') }}
                </SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="item in adminNavItems"
                        :key="item.title"
                    >
                        <SidebarMenuButton
                            as-child
                            :is-active="item.active"
                            :tooltip="item.title"
                            class="group relative rounded-lg font-medium transition-all data-[active=true]:bg-onda-blue-600/10 data-[active=true]:font-semibold data-[active=true]:text-onda-blue-700 dark:data-[active=true]:bg-onda-blue-500/20 dark:data-[active=true]:text-onda-blue-400"
                        >
                            <Link
                                :href="item.href"
                                class="flex w-full items-center gap-3 text-start"
                            >
                                <component
                                    :is="item.icon"
                                    class="size-4 shrink-0 transition-transform group-hover:scale-110"
                                />
                                <span class="truncate">{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>

            <!-- Hotline Widget for Creators -->
            <template v-if="isAuthor">
                <!-- Expanded view: Full detailed card -->
                <div
                    class="mx-3 mt-auto mb-2 rounded-xl border border-onda-blue-500/20 bg-gradient-to-br from-onda-blue-500/5 to-onda-teal-500/5 p-3 group-data-[collapsible=icon]:hidden dark:from-onda-blue-950/40 dark:to-onda-teal-950/40"
                >
                    <div class="mb-1.5 flex items-center gap-2">
                        <div
                            class="flex size-6 items-center justify-center rounded-md bg-onda-blue-600/10 text-onda-blue-600 dark:bg-onda-blue-500/20 dark:text-onda-blue-400"
                        >
                            <Headphones class="size-3.5" />
                        </div>
                        <span class="text-xs font-semibold text-foreground">
                            {{ t('sidebar.hotline.title') }}
                        </span>
                    </div>
                    <a
                        :href="`tel:${t('sidebar.hotline.number').replace(/\s+/g, '')}`"
                        class="dir-ltr block text-start font-mono text-xs font-bold tracking-wider text-onda-blue-700 transition-colors hover:underline dark:text-onda-blue-400"
                    >
                        {{ t('sidebar.hotline.number') }}
                    </a>
                    <p class="mt-0.5 text-[10px] text-muted-foreground">
                        Dimanche – Jeudi (08:30 - 16:30)
                    </p>
                </div>

                <!-- Collapsed view: Compact icon button with popout tooltip -->
                <div
                    class="mt-auto mb-2 hidden justify-center group-data-[collapsible=icon]:flex"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <a
                                :href="`tel:${t('sidebar.hotline.number').replace(/\s+/g, '')}`"
                                class="flex size-8 items-center justify-center rounded-lg border border-onda-blue-500/20 bg-onda-blue-500/10 text-onda-blue-600 transition-all hover:scale-105 hover:bg-onda-blue-500/20 active:scale-95 dark:bg-onda-blue-500/20 dark:text-onda-blue-400"
                                :aria-label="t('sidebar.hotline.title')"
                            >
                                <Headphones class="size-4" />
                            </a>
                        </TooltipTrigger>
                        <TooltipContent
                            :side="isRtl ? 'left' : 'right'"
                            align="center"
                            :side-offset="8"
                            :hidden="state !== 'collapsed' || isMobile"
                            class="p-2.5 shadow-xl"
                        >
                            <div class="space-y-1 min-w-[140px]">
                                <div class="flex items-center gap-2">
                                    <Headphones class="size-3.5 shrink-0" />
                                    <span class="text-xs font-semibold">
                                        {{ t('sidebar.hotline.title') }}
                                    </span>
                                </div>
                                <p class="dir-ltr text-start font-mono text-xs font-bold tracking-wider">
                                    {{ t('sidebar.hotline.number') }}
                                </p>
                                <p class="text-[10px] opacity-80 whitespace-nowrap">
                                    Dimanche – Jeudi (08:30 - 16:30)
                                </p>
                            </div>
                        </TooltipContent>
                    </Tooltip>
                </div>
            </template>
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border/60 pt-2">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
