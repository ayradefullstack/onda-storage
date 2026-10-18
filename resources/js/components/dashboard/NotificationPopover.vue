<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Ban,
    Bell,
    Clock,
    FileCheck2,
    Info,
    SearchCheck,
    ShieldCheck,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { oeuvreLabel } from '@/components/oeuvre/label';
import type { LabelSource } from '@/components/oeuvre/label';
import { exactDateTime, relativeTime } from '@/components/review/relativeTime';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    index as notificationsIndex,
    read as markRead,
    readAll as markAllRead,
} from '@/routes/notifications';

/**
 * The header bell: the reader's own rows from `/notifications`
 * (NotificationController), newest first, with the unread count.
 *
 * A stored notification is an i18n key plus its parameters, never a
 * sentence — it is rendered here, in the reader's current language, and
 * re-renders if they switch (see OeuvreNotification). A rejection carries
 * the officer's reason verbatim, shown as its own block.
 *
 * Loading is a plain `fetch`, not an Inertia visit: it runs on mount, when
 * the bell opens and after each navigation, and must never raise the
 * loading screen or touch the page's props.
 */
interface NotificationData {
    type?: string;
    i18n_key?: string;
    params?: Record<string, string | number | null>;
    label_source?: LabelSource;
    reason?: string;
    url?: string;
}

interface NotificationRow {
    id: string;
    data: NotificationData;
    read_at: string | null;
    created_at: string | null;
}

interface Payload {
    unread_count: number;
    notifications: NotificationRow[];
}

const { t, te, locale } = useI18n();

const notifications = ref<NotificationRow[]>([]);
const unreadCount = ref(0);
const loaded = ref(false);
const failed = ref(false);

function xsrfToken(): string | null {
    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return match === undefined
        ? null
        : decodeURIComponent(match.slice('XSRF-TOKEN='.length));
}

async function request(url: string, method: 'GET' | 'POST'): Promise<void> {
    try {
        const token = xsrfToken();
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(method === 'POST' && token !== null
                    ? { 'X-XSRF-TOKEN': token }
                    : {}),
            },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const payload = (await response.json()) as Payload;

        notifications.value = payload.notifications;
        unreadCount.value = payload.unread_count;
        failed.value = false;
    } catch {
        // Keep whatever is already shown; only an empty bell says so.
        failed.value = true;
    } finally {
        loaded.value = true;
    }
}

const refresh = (): Promise<void> => request(notificationsIndex().url, 'GET');

let stopNavigate: (() => void) | null = null;

onMounted(() => {
    void refresh();
    // The header outlives page visits; a decision made on one page should
    // not wait for a full reload to reach the bell.
    stopNavigate = router.on('navigate', () => void refresh());
});

onBeforeUnmount(() => stopNavigate?.());

function onOpenChange(open: boolean): void {
    if (open) {
        void refresh();
    }
}

function markAllAsRead(): void {
    void request(markAllRead().url, 'POST');
}

/** Same-origin path of the page a row points at, or null. */
function target(item: NotificationRow): string | null {
    if (!item.data.url) {
        return null;
    }

    try {
        const url = new URL(item.data.url, window.location.origin);

        return `${url.pathname}${url.search}`;
    } catch {
        return null;
    }
}

function open(item: NotificationRow): void {
    if (item.read_at === null) {
        // Optimistic: the row is read the moment it is clicked.
        item.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        void request(markRead(item.id).url, 'POST');
    }

    const path = target(item);

    if (path !== null) {
        router.visit(path);
    }
}

function title(item: NotificationRow): string {
    const source = item.data.label_source;

    return source
        ? oeuvreLabel(source, locale.value, t('oeuvres.untitled'))
        : t('notifications.generic');
}

function message(item: NotificationRow): string {
    const key = item.data.i18n_key;

    if (!key || !te(key)) {
        return t('notifications.generic');
    }

    const params: Record<string, string> = {};

    for (const [name, value] of Object.entries(item.data.params ?? {})) {
        params[name] = value === null ? '' : String(value);
    }

    return t(key, params);
}

const kind = (item: NotificationRow): string =>
    (item.data.i18n_key ?? '').split('.').pop() ?? '';

function icon(item: NotificationRow): Component {
    switch (kind(item)) {
        case 'registered':
            return ShieldCheck;
        case 'rejected':
            return Ban;
        case 'under_review':
            return SearchCheck;
        case 'submitted':
        case 'resubmitted':
            return FileCheck2;
        default:
            return Info;
    }
}

function iconColor(item: NotificationRow): string {
    switch (kind(item)) {
        case 'registered':
            return 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400';
        case 'rejected':
            return 'bg-destructive/10 text-destructive';
        case 'under_review':
            return 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400';
        default:
            return 'bg-onda-blue-500/10 text-onda-blue-600 dark:bg-onda-blue-500/20 dark:text-onda-blue-400';
    }
}
</script>

<template>
    <DropdownMenu @update:open="onOpenChange">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative h-9 w-9 rounded-lg text-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="
                    unreadCount > 0
                        ? `${t('dashboard.notifications.title')} — ${t('dashboard.notifications.unreadCount', { count: unreadCount })}`
                        : t('dashboard.notifications.title')
                "
                data-testid="notification-bell"
            >
                <Bell class="size-4" />
                <span
                    v-if="unreadCount > 0"
                    class="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-onda-blue-600 px-1 text-[10px] font-bold text-white shadow-xs dark:bg-onda-teal-500 dark:text-gray-950"
                    data-testid="notification-unread"
                >
                    <bdi dir="ltr">{{
                        unreadCount > 99 ? '99+' : unreadCount
                    }}</bdi>
                </span>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            align="end"
            class="w-80 rounded-xl border border-border/80 bg-popover/95 p-0 shadow-xl backdrop-blur-md sm:w-96"
        >
            <div
                class="flex items-center justify-between border-b border-border/60 px-4 py-3"
            >
                <div class="flex items-center gap-2">
                    <h4 class="text-sm font-semibold text-foreground">
                        {{ t('dashboard.notifications.title') }}
                    </h4>
                    <Badge
                        v-if="unreadCount > 0"
                        variant="secondary"
                        class="h-4 border-onda-blue-500/20 bg-onda-blue-500/10 px-1.5 py-0 font-mono text-[10px] font-semibold text-onda-blue-600 dark:text-onda-blue-400"
                    >
                        {{ unreadCount }}
                    </Badge>
                </div>
                <button
                    v-if="unreadCount > 0"
                    type="button"
                    class="cursor-pointer rounded text-xs font-medium text-onda-blue-600 transition-colors outline-none hover:text-onda-blue-700 focus-visible:ring-2 focus-visible:ring-ring/40 dark:text-onda-blue-400 dark:hover:text-onda-blue-300"
                    data-testid="notification-read-all"
                    @click="markAllAsRead"
                >
                    {{ t('dashboard.notifications.markAllRead') }}
                </button>
            </div>

            <div
                class="max-h-[380px] divide-y divide-border/40 overflow-y-auto overscroll-contain p-1"
            >
                <p
                    v-if="notifications.length === 0"
                    class="px-4 py-8 text-center text-xs text-muted-foreground"
                    data-testid="notification-empty"
                >
                    {{
                        !loaded
                            ? t('notifications.loading')
                            : failed
                              ? t('notifications.loadFailed')
                              : t('dashboard.notifications.empty')
                    }}
                </p>

                <DropdownMenuItem
                    v-for="item in notifications"
                    :key="item.id"
                    :class="[
                        'flex cursor-pointer items-start gap-3 rounded-lg p-3',
                        item.read_at
                            ? 'opacity-75 focus:opacity-100'
                            : 'bg-onda-blue-500/5 dark:bg-onda-blue-950/30',
                    ]"
                    data-testid="notification-item"
                    :data-type="item.data.type"
                    :data-read="item.read_at !== null"
                    @select="open(item)"
                >
                    <div
                        :class="[
                            'flex size-8 shrink-0 items-center justify-center rounded-lg',
                            iconColor(item),
                        ]"
                    >
                        <component
                            :is="icon(item)"
                            class="size-4 text-current"
                        />
                    </div>

                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p
                                class="truncate text-xs font-semibold text-foreground"
                            >
                                <bdi>{{ title(item) }}</bdi>
                            </p>
                            <span
                                v-if="item.created_at"
                                class="flex shrink-0 items-center gap-1 text-[10px] text-muted-foreground"
                                :title="exactDateTime(item.created_at, locale)"
                            >
                                <Clock class="size-2.5" />
                                <bdi>{{
                                    relativeTime(item.created_at, locale)
                                }}</bdi>
                            </span>
                        </div>
                        <p
                            class="text-[11px] leading-relaxed whitespace-normal text-muted-foreground"
                        >
                            {{ message(item) }}
                        </p>
                        <p
                            v-if="item.data.reason"
                            class="line-clamp-3 rounded border-s-2 border-destructive/40 bg-muted/60 px-2 py-1 text-[11px] leading-relaxed whitespace-normal text-foreground"
                            data-testid="notification-reason"
                        >
                            <span class="sr-only"
                                >{{ t('notifications.reason') }} :
                            </span>
                            <bdi dir="auto">{{ item.data.reason }}</bdi>
                        </p>
                    </div>

                    <span
                        v-if="!item.read_at"
                        class="size-1.5 shrink-0 self-center rounded-full bg-onda-blue-600 dark:bg-onda-teal-400"
                    />
                </DropdownMenuItem>
            </div>

            <div class="border-t border-border/60 bg-muted/20 p-2 text-center">
                <p class="text-[11px] text-muted-foreground">
                    {{ t('dashboard.sovereignBadge') }}
                </p>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
