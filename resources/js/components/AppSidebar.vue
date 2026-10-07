<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Files,
    FolderOpen,
    Headphones,
    Languages,
    LayoutGrid,
    Library,
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
import { useDirection } from '@/composables/useDirection';
import { toUrl } from '@/lib/utils';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminAuthorsIndex } from '@/routes/admin/authors';
import { index as adminLanguagesIndex } from '@/routes/admin/languages';
import { index as adminOeuvresIndex } from '@/routes/admin/oeuvres';
import { colleges as adminReferentielColleges } from '@/routes/admin/referentiel';
import { dashboard as authorDashboard } from '@/routes/author';
import { index as oeuvresIndex } from '@/routes/oeuvres';

const { t } = useI18n();
const page = usePage();
const { isMobile, state } = useSidebar();

const { isRtl } = useDirection();
const roles = computed(() => page.props.auth.roles);
const isAuthor = computed(() => roles.value.includes('author'));
const isAdmin = computed(() => roles.value.includes('admin'));

function isActive(href: Parameters<typeof toUrl>[0]): boolean {
    return page.url === toUrl(href) || page.url.startsWith(toUrl(href) + '/');
}

const mainNavItems = computed<
    {
        title: string;
        href: ReturnType<typeof authorDashboard>;
        icon: typeof LayoutGrid;
        active: boolean;
        badge?: string | number;
    }[]
>(() => [
    {
        title: t('sidebar.nav.overview'),
        href: authorDashboard(),
        icon: LayoutGrid,
        active: isActive(authorDashboard()),
    },
    {
        title: t('sidebar.nav.oeuvres'),
        href: oeuvresIndex(),
        icon: Files,
        active: isActive(oeuvresIndex()),
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
        title: t('admin.oeuvres.title'),
        href: adminOeuvresIndex(),
        icon: FolderOpen,
        active: isActive(adminOeuvresIndex()),
    },
    {
        // Colleges is the tab an officer wants most often, so it is the
        // section's landing point; the tab bar reaches the other four.
        title: t('admin.referentiel.title'),
        href: adminReferentielColleges(),
        icon: Library,
        active: page.url.startsWith('/admin/referentiel'),
    },
    {
        title: t('sidebar.nav.languages'),
        href: adminLanguagesIndex(),
        icon: Languages,
        active: isActive(adminLanguagesIndex()),
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
                                :tooltip-side="isRtl ? 'left' : 'right'"
                                class="group relative rounded-lg font-medium transition-colors duration-150 data-[active=true]:bg-primary/10 data-[active=true]:font-semibold data-[active=true]:text-primary dark:data-[active=true]:bg-primary/15 dark:data-[active=true]:text-primary"
                            >
                                <Link
                                    :href="item.href"
                                    class="flex w-full items-center gap-3 text-start"
                                >
                                    <component
                                        :is="item.icon"
                                        :stroke-width="1.75"
                                        class="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground group-data-[active=true]:text-primary"
                                    />
                                    <span class="truncate">{{
                                        item.title
                                    }}</span>
                                    <span
                                        v-if="item.badge"
                                        class="ms-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 px-1.5 font-mono text-[10px] font-bold text-primary dark:bg-primary/20 dark:text-primary"
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
                            :tooltip-side="isRtl ? 'left' : 'right'"
                            class="group relative rounded-lg font-medium transition-colors duration-150 data-[active=true]:bg-primary/10 data-[active=true]:font-semibold data-[active=true]:text-primary dark:data-[active=true]:bg-primary/15 dark:data-[active=true]:text-primary"
                        >
                            <Link
                                :href="item.href"
                                class="flex w-full items-center gap-3 text-start"
                            >
                                <component
                                    :is="item.icon"
                                    :stroke-width="1.75"
                                    class="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground group-data-[active=true]:text-primary"
                                />
                                <span class="truncate">{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>

            <!-- Hotline Widget for Creators -->
            <template v-if="isAuthor">
                <!-- Expanded view: Full clean card -->
                <div
                    class="mx-3 mt-auto mb-2 rounded-xl border border-border bg-card/60 p-3 shadow-xs group-data-[collapsible=icon]:hidden"
                >
                    <div class="mb-1.5 flex items-center gap-2">
                        <div
                            class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                        >
                            <Headphones :stroke-width="1.75" class="size-3.5" />
                        </div>
                        <span class="text-xs font-semibold text-foreground">
                            {{ t('sidebar.hotline.title') }}
                        </span>
                    </div>
                    <a
                        :href="`tel:${t('sidebar.hotline.number').replace(/\s+/g, '')}`"
                        class="dir-ltr block text-start font-mono text-xs font-semibold text-primary transition-colors hover:underline"
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
                                class="flex size-8 items-center justify-center rounded-lg border border-border bg-card/60 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                :aria-label="t('sidebar.hotline.title')"
                            >
                                <Headphones
                                    :stroke-width="1.75"
                                    class="size-4"
                                />
                            </a>
                        </TooltipTrigger>
                        <TooltipContent
                            :side="isRtl ? 'left' : 'right'"
                            align="center"
                            :side-offset="8"
                            :hidden="state !== 'collapsed' || isMobile"
                            class="border border-border bg-popover p-2.5 shadow-md"
                        >
                            <div class="min-w-[140px] space-y-1">
                                <div class="flex items-center gap-2">
                                    <Headphones
                                        :stroke-width="1.75"
                                        class="size-3.5 shrink-0 text-primary"
                                    />
                                    <span
                                        class="text-xs font-semibold text-foreground"
                                    >
                                        {{ t('sidebar.hotline.title') }}
                                    </span>
                                </div>
                                <p
                                    class="dir-ltr text-start font-mono text-xs font-semibold text-primary"
                                >
                                    {{ t('sidebar.hotline.number') }}
                                </p>
                                <p
                                    class="text-[10px] whitespace-nowrap text-muted-foreground"
                                >
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
