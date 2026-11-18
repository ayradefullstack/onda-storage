<script setup lang="ts">
import { X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    DialogTrigger,
} from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';
import AppDialogBody from './AppDialogBody.vue';
import AppDialogFooter from './AppDialogFooter.vue';

/**
 * THE modal of the app, on top of the reka-ui Dialog (focus trap, focus
 * return to the trigger, scroll lock with scrollbar compensation).
 *
 * Layout: a header (title + close) and a footer (actions) that never move,
 * around a body that scrolls on its own. Long content cannot push the
 * buttons off-screen. On small screens the panel is a sheet rising from the
 * bottom edge; from `sm` up it is centred.
 *
 * `busy` is for an action in flight (a delete, a submit): Escape, the close
 * button and a click outside are all ignored until it clears.
 *
 * Slots: `trigger` (optional, makes the dialog self-opening), `icon`,
 * `title`, `description`, `actions` (header buttons before the close),
 * `header-extra` (under the title, still in the fixed header), the default
 * slot (the scrolling body) and `footer`. A dialog whose body and footer
 * must share one element (a form with slot props) uses `content` instead
 * and places <AppDialogBody> / <AppDialogFooter> itself.
 */
type Size = 'sm' | 'md' | 'lg' | 'xl' | 'full';

const props = withDefaults(
    defineProps<{
        /** Leave undefined (with a `trigger` slot) for a self-managed dialog. */
        open?: boolean;
        title?: string;
        description?: string;
        size?: Size;
        busy?: boolean;
        /** Render the frame as a <form> and emit `submit`. */
        form?: boolean;
        /** Remove the body padding (for a body that draws its own). */
        flush?: boolean;
        footerClass?: HTMLAttributes['class'];
        class?: HTMLAttributes['class'];
    }>(),
    {
        open: undefined,
        title: undefined,
        description: undefined,
        size: 'md',
        busy: false,
        form: false,
        flush: false,
        footerClass: undefined,
        class: undefined,
    },
);

const emit = defineEmits<{
    'update:open': [open: boolean];
    submit: [];
}>();

const { t } = useI18n();

const SIZES: Record<Size, string> = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-4xl',
    full: 'max-h-dvh sm:h-[calc(100dvh-2rem)] sm:max-h-none sm:max-w-[calc(100vw-2rem)]',
};

function onOpenChange(open: boolean): void {
    if (!open && props.busy) {
        return;
    }

    emit('update:open', open);
}

function guard(event: Event): void {
    if (props.busy) {
        event.preventDefault();
    }
}
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </DialogTrigger>
        <DialogPortal>
            <DialogOverlay
                data-slot="app-dialog-overlay"
                class="app-dialog-overlay fixed inset-0 z-50 bg-black/80"
            />
            <DialogContent
                data-slot="app-dialog"
                :data-size="size"
                :aria-busy="busy"
                :class="
                    cn(
                        'app-dialog-panel fixed inset-x-0 bottom-0 z-50 flex max-h-[min(90dvh,48rem)] w-full flex-col overflow-hidden rounded-t-xl border bg-background shadow-lg outline-none sm:inset-0 sm:m-auto sm:h-fit sm:w-[calc(100%-2rem)] sm:rounded-lg',
                        SIZES[size],
                        props.class,
                    )
                "
                @escape-key-down="guard"
                @pointer-down-outside="guard"
                @interact-outside="guard"
            >
                <header
                    data-slot="app-dialog-header"
                    class="shrink-0 border-b px-5 py-4"
                >
                    <div class="flex items-start gap-3">
                        <slot name="icon" />
                        <div class="min-w-0 flex-1 text-start">
                            <DialogTitle
                                class="text-base leading-snug font-semibold"
                            >
                                <slot name="title">{{ title }}</slot>
                            </DialogTitle>
                            <DialogDescription
                                v-if="description || $slots.description"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                <slot name="description">{{
                                    description
                                }}</slot>
                            </DialogDescription>
                        </div>
                        <slot name="actions" />
                        <DialogClose
                            data-slot="app-dialog-close"
                            :disabled="busy"
                            :aria-label="t('ui.close')"
                            class="-me-1.5 -mt-1 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-50"
                        >
                            <X class="size-4" />
                        </DialogClose>
                    </div>
                    <slot name="header-extra" />
                </header>

                <slot
                    name="content"
                    :close="() => onOpenChange(false)"
                >
                    <component
                        :is="form ? 'form' : 'div'"
                        class="flex min-h-0 flex-auto flex-col"
                        @submit.prevent="emit('submit')"
                    >
                        <AppDialogBody :flush="flush">
                            <slot />
                        </AppDialogBody>
                        <AppDialogFooter
                            v-if="$slots.footer"
                            :class="footerClass"
                        >
                            <slot name="footer" />
                        </AppDialogFooter>
                    </component>
                </slot>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
