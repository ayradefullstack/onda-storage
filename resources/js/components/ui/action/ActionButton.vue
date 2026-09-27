<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Download,
    Eye,
    Loader2,
    Pencil,
    Plus,
    RotateCcw,
    Send,
    Trash2,
    Upload,
} from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type ActionType =
    | 'view'
    | 'edit'
    | 'delete'
    | 'download'
    | 'upload'
    | 'add'
    | 'restore'
    | 'copy'
    | 'submit';

interface Props {
    action: ActionType;
    label?: string;
    ariaLabel?: string;
    href?: string;
    iconOnly?: boolean;
    size?: 'sm' | 'default' | 'lg';
    disabled?: boolean;
    loading?: boolean;
    copied?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    iconOnly: true,
    size: 'sm',
    disabled: false,
    loading: false,
    copied: false,
});

const emit = defineEmits<{
    (e: 'click', event: MouseEvent): void;
}>();

const actionIconMap = {
    view: Eye,
    edit: Pencil,
    delete: Trash2,
    download: Download,
    upload: Upload,
    add: Plus,
    restore: RotateCcw,
    copy: Copy,
    submit: Send,
};

const currentIcon = computed(() => {
    if (props.loading) return Loader2;
    if (props.action === 'copy' && props.copied) return Check;
    return actionIconMap[props.action];
});

const defaultLabels: Record<ActionType, string> = {
    view: 'Consulter',
    edit: 'Modifier',
    delete: 'Supprimer',
    download: 'Télécharger',
    upload: 'Téléverser',
    add: 'Ajouter',
    restore: 'Restaurer',
    copy: 'Copier',
    submit: 'Soumettre',
};

const resolvedLabel = computed(() => {
    if (props.action === 'copy' && props.copied) return 'Copié !';
    return props.label || defaultLabels[props.action];
});

const resolvedAriaLabel = computed(() => {
    return props.ariaLabel || resolvedLabel.value;
});

const colorClasses = computed(() => {
    switch (props.action) {
        case 'view':
            return 'border border-border/80 bg-muted/60 text-slate-700 hover:bg-muted hover:text-slate-950 hover:border-border dark:border-border/60 dark:bg-muted/40 dark:text-slate-300 dark:hover:bg-muted/70 dark:hover:text-slate-100 focus-visible:ring-slate-400';
        case 'edit':
            return 'border border-sky-500/25 bg-sky-500/10 text-sky-700 hover:bg-sky-500/20 hover:text-sky-800 hover:border-sky-500/40 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-300 dark:hover:bg-sky-500/25 dark:hover:text-sky-200 focus-visible:ring-sky-500';
        case 'delete':
            return 'border border-rose-500/25 bg-rose-500/10 text-rose-700 hover:bg-rose-500/20 hover:text-rose-800 hover:border-rose-500/40 dark:border-rose-500/30 dark:bg-rose-500/15 dark:text-rose-300 dark:hover:bg-rose-500/25 dark:hover:text-rose-200 focus-visible:ring-rose-500';
        case 'submit':
            return 'border border-emerald-500/25 bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/20 hover:text-emerald-800 hover:border-emerald-500/40 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/25 dark:hover:text-emerald-200 focus-visible:ring-emerald-500';
        case 'download':
            return 'border border-indigo-500/25 bg-indigo-500/10 text-indigo-700 hover:bg-indigo-500/20 hover:text-indigo-800 hover:border-indigo-500/40 dark:border-indigo-500/30 dark:bg-indigo-500/15 dark:text-indigo-300 dark:hover:bg-indigo-500/25 dark:hover:text-indigo-200 focus-visible:ring-indigo-500';
        case 'upload':
        case 'add':
            return 'border border-emerald-500/25 bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/20 hover:text-emerald-800 hover:border-emerald-500/40 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/25 dark:hover:text-emerald-200 focus-visible:ring-emerald-500';
        case 'restore':
            return 'border border-amber-500/25 bg-amber-500/10 text-amber-700 hover:bg-amber-500/20 hover:text-amber-800 hover:border-amber-500/40 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300 dark:hover:bg-amber-500/25 dark:hover:text-amber-200 focus-visible:ring-amber-500';
        case 'copy':
            return props.copied
                ? 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-300 focus-visible:ring-emerald-500'
                : 'border border-border/80 bg-muted/60 text-slate-700 hover:bg-muted hover:text-slate-950 dark:border-border/60 dark:bg-muted/40 dark:text-slate-300 dark:hover:bg-muted/70 focus-visible:ring-slate-400';
    }
});

const sizeClasses = computed(() => {
    if (props.iconOnly) {
        switch (props.size) {
            case 'sm':
                return 'size-8 rounded-md p-1.5';
            case 'lg':
                return 'size-10 rounded-lg p-2.5';
            default:
                return 'size-9 rounded-lg p-2';
        }
    } else {
        switch (props.size) {
            case 'sm':
                return 'h-8 px-2.5 gap-1.5 rounded-md text-xs font-medium';
            case 'lg':
                return 'h-10 px-4 gap-2 rounded-lg text-sm font-semibold';
            default:
                return 'h-9 px-3 gap-2 rounded-lg text-xs md:text-sm font-medium';
        }
    }
});

const iconSizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'size-3.5';
        case 'lg':
            return 'size-4.5';
        default:
            return 'size-4';
    }
});
</script>

<template>
    <TooltipProvider :delay-duration="200" v-if="iconOnly">
        <Tooltip>
            <TooltipTrigger as-child>
                <component
                    :is="href ? Link : 'button'"
                    :href="href"
                    :type="href ? undefined : 'button'"
                    :disabled="disabled || loading"
                    :aria-label="resolvedAriaLabel"
                    :class="
                        cn(
                            'inline-flex items-center justify-center transition-colors duration-150 select-none cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-40',
                            colorClasses,
                            sizeClasses,
                            props.class,
                        )
                    "
                    @click="emit('click', $event)"
                >
                    <component
                        :is="currentIcon"
                        :stroke-width="1.75"
                        :class="
                            cn(
                                iconSizeClasses,
                                loading ? 'animate-spin' : '',
                            )
                        "
                    />
                </component>
            </TooltipTrigger>
            <TooltipContent side="top" :side-offset="4" class="text-xs font-medium">
                {{ resolvedLabel }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>

    <component
        v-else
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : 'button'"
        :disabled="disabled || loading"
        :aria-label="resolvedAriaLabel"
        :class="
            cn(
                'inline-flex items-center justify-center transition-colors duration-150 select-none cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-40',
                colorClasses,
                sizeClasses,
                props.class,
            )
        "
        @click="emit('click', $event)"
    >
        <component
            :is="currentIcon"
            :stroke-width="1.75"
            :class="
                cn(iconSizeClasses, loading ? 'animate-spin' : '')
            "
        />
        <span>{{ resolvedLabel }}</span>
    </component>
</template>
