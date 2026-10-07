<script setup lang="ts">
import {
    BadgeCheck,
    Landmark,
    Scale,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Card, CardContent } from '@/components/ui/card';

/** The branch an oeuvre was filed under, as the show page receives it. */
export interface OeuvreClassification {
    type: string;
    /** Auteur only — the other declarant types have no gestion. */
    gestion: string | null;
    college: string;
    /** The snapshot taken at filing, not the college's current code. */
    code_college: string | null;
    member: string;
    code_qlt: string | null;
}

const props = defineProps<{
    classification: OeuvreClassification;
}>();

const { t } = useI18n();

interface Row {
    key: string;
    icon: Component;
    label: string;
    value: string;
    code: string | null;
}

const rows = computed<Row[]>(() => {
    const c = props.classification;
    const list: Row[] = [
        {
            key: 'type',
            icon: UserRound,
            label: t('oeuvres.classification.type'),
            value: c.type,
            code: null,
        },
    ];

    if (c.gestion !== null) {
        list.push({
            key: 'gestion',
            icon: Scale,
            label: t('oeuvres.classification.gestion'),
            value: c.gestion,
            code: null,
        });
    }

    list.push(
        {
            key: 'college',
            icon: Landmark,
            label: t('oeuvres.classification.college'),
            value: c.college,
            code: c.code_college,
        },
        {
            key: 'member',
            icon: BadgeCheck,
            label: t('oeuvres.classification.member'),
            value: c.member,
            code: c.code_qlt,
        },
    );

    return list;
});
</script>

<template>
    <Card>
        <CardContent class="py-5">
            <div class="mb-4 flex items-start gap-3">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-onda-blue-500/10 text-onda-blue-600 dark:text-onda-blue-400"
                >
                    <ShieldCheck class="size-4.5" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-medium">
                        {{ t('oeuvres.show.classificationTitle') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ t('oeuvres.show.classificationHint') }}
                    </p>
                </div>
            </div>

            <dl
                class="grid gap-3 sm:grid-cols-2"
                :class="rows.length === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3'"
            >
                <div
                    v-for="row in rows"
                    :key="row.key"
                    class="min-w-0 rounded-xl border border-border/70 bg-muted/30 p-3"
                >
                    <dt
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <component :is="row.icon" class="size-3.5 shrink-0" />
                        {{ row.label }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium break-words">
                        <bdi>{{ row.value }}</bdi>
                    </dd>
                    <dd v-if="row.code" class="mt-1.5">
                        <span
                            class="rounded-md border border-border/70 bg-background px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                            ><bdi dir="ltr">{{ row.code }}</bdi></span
                        >
                    </dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
