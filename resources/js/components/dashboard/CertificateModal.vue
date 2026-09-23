<script setup lang="ts">
import {
    Award,
    CheckCircle2,
    Copy,
    Printer,
    QrCode,
    ShieldCheck,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Work } from '@/components/dashboard/DashboardWorksTable.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

const { t, locale } = useI18n();

const props = defineProps<{
    work: Work | null;
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const copied = ref(false);

const copyHash = async () => {
    if (!props.work?.hash) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.work.hash);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // fallback
    }
};

const handlePrint = () => {
    window.print();
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="max-w-2xl overflow-hidden rounded-2xl border border-onda-blue-500/30 bg-card p-0 shadow-2xl"
        >
            <!-- Modal Top Bar -->
            <div
                class="flex items-center justify-between border-b border-border/80 bg-muted/40 px-5 py-3.5"
            >
                <div class="flex items-center gap-2">
                    <Award
                        class="size-4.5 text-onda-blue-600 dark:text-onda-blue-400"
                    />
                    <DialogTitle class="text-sm font-bold text-foreground">
                        {{ t('dashboard.cert.heading') }}
                    </DialogTitle>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 cursor-pointer gap-1.5 rounded-lg text-xs"
                        @click="handlePrint"
                    >
                        <Printer class="size-3.5" />
                        <span>{{ t('dashboard.cert.printBtn') }}</span>
                    </Button>
                </div>
            </div>

            <!-- Authentic Official Certificate Body -->
            <div v-if="work" class="space-y-6 p-6 text-center sm:p-8 print:p-0">
                <!-- Official Institutional Header -->
                <div class="space-y-1 border-b border-border/60 pb-5">
                    <p
                        class="text-xs font-bold tracking-widest text-muted-foreground uppercase"
                    >
                        {{ t('dashboard.cert.officialTitle') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('dashboard.cert.ministry') }}
                    </p>
                    <h3
                        class="pt-1 text-base font-black tracking-tight text-onda-blue-700 sm:text-lg dark:text-onda-blue-400"
                    >
                        {{ t('dashboard.cert.ondaFull') }}
                    </h3>
                    <div class="flex justify-center pt-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600 shadow-2xs dark:text-emerald-400"
                        >
                            <ShieldCheck class="size-3.5" />
                            {{ t('dashboard.cert.officialProof') }}
                        </span>
                    </div>
                </div>

                <!-- Certificate Metadata Grid -->
                <div
                    class="space-y-4 rounded-xl border border-border/80 bg-muted/15 p-5 text-start"
                >
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <span
                                class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                {{ t('dashboard.cert.workTitle') }}
                            </span>
                            <p
                                class="mt-0.5 text-sm font-bold text-foreground sm:text-base"
                            >
                                {{
                                    locale === 'ar' ? work.titleAr : work.title
                                }}
                            </p>
                        </div>

                        <div>
                            <span
                                class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                {{ t('dashboard.cert.author') }}
                            </span>
                            <p
                                class="mt-0.5 text-sm font-bold text-foreground sm:text-base"
                            >
                                {{ work.authorName }}
                            </p>
                        </div>

                        <div>
                            <span
                                class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                {{ t('dashboard.table.colRef') }}
                            </span>
                            <p
                                class="mt-0.5 font-mono text-xs font-bold text-onda-blue-700 dark:text-onda-blue-400"
                            >
                                {{ work.reference }}
                            </p>
                        </div>

                        <div>
                            <span
                                class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                {{ t('dashboard.cert.depositDate') }}
                            </span>
                            <p
                                class="mt-0.5 text-xs font-medium text-foreground"
                            >
                                {{ work.date }} (14:32:08 UTC+1)
                            </p>
                        </div>
                    </div>

                    <!-- Cryptographic SHA-256 Checksum Container -->
                    <div class="border-t border-border/60 pt-3">
                        <span
                            class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {{ t('dashboard.cert.sha256') }}
                        </span>
                        <div
                            class="mt-1 flex items-center justify-between gap-2 rounded-lg border border-border bg-background p-2.5 font-mono text-[11px] text-foreground"
                        >
                            <span class="truncate">{{ work.hash }}</span>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="h-6 shrink-0 cursor-pointer px-2 text-[10px] text-onda-blue-600 dark:text-onda-blue-400"
                                @click="copyHash"
                            >
                                <component
                                    :is="copied ? CheckCircle2 : Copy"
                                    class="me-1 size-3"
                                />
                                <span>{{ copied ? 'Copié' : 'Copier' }}</span>
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- QR Code Verification Footer -->
                <div
                    class="flex flex-col items-center justify-between gap-4 border-t border-border/60 pt-4 text-start sm:flex-row"
                >
                    <div class="flex items-center gap-3">
                        <!-- Simulated SVG QR code graphic -->
                        <div
                            class="flex size-16 shrink-0 items-center justify-center rounded-lg border border-border bg-white p-1.5 shadow-xs"
                        >
                            <QrCode class="size-13 text-gray-900" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-xs font-bold text-foreground">
                                {{ t('cert.instantVerify') }}
                            </p>
                            <p
                                class="max-w-xs text-[11px] leading-relaxed text-muted-foreground"
                            >
                                {{ t('dashboard.cert.qrNotice') }}
                            </p>
                        </div>
                    </div>

                    <p
                        class="max-w-[200px] text-center text-[10px] leading-relaxed text-muted-foreground sm:text-end"
                    >
                        {{ t('dashboard.cert.legalRef') }}
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
