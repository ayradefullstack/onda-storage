<script setup lang="ts">
import {
    BookOpen,
    CheckCircle2,
    Clapperboard,
    CloudUpload,
    CodeXml,
    Music,
    ShieldCheck,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Spinner from '@/components/ui/spinner/Spinner.vue';

const { t } = useI18n();

defineProps<{
    open: boolean;
    initialCategory?: string;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'deposit-created', payload: any): void;
}>();

const step = ref<number>(1);
const selectedCategory = ref<string>('music');
const workTitle = ref('');
const workYear = ref(new Date().getFullYear());
const workDesc = ref('');
const fileName = ref('');
const fileSize = ref('');
const isSubmitting = ref(false);
const depositSuccess = ref(false);

const categories = [
    { id: 'music', titleKey: 'dashboard.quickActions.music', icon: Music },
    {
        id: 'literature',
        titleKey: 'dashboard.quickActions.literature',
        icon: BookOpen,
    },
    {
        id: 'cinema',
        titleKey: 'dashboard.quickActions.cinema',
        icon: Clapperboard,
    },
    {
        id: 'software',
        titleKey: 'dashboard.quickActions.software',
        icon: CodeXml,
    },
];

const handleFileSelect = (e: Event) => {
    const target = e.target as HTMLInputElement;

    if (target.files && target.files[0]) {
        fileName.value = target.files[0].name;
        fileSize.value =
            (target.files[0].size / (1024 * 1024)).toFixed(1) + ' MB';
    }
};

const handleNext = () => {
    if (step.value < 3) {
        step.value++;
    } else {
        submitDeposit();
    }
};

const submitDeposit = () => {
    isSubmitting.value = true;
    setTimeout(() => {
        isSubmitting.value = false;
        depositSuccess.value = true;
        emit('deposit-created', {
            title: workTitle.value || 'Nouvel Enregistrement ONDA',
            titleAr: workTitle.value || 'تسجيل جديد ONDA',
            category: selectedCategory.value,
            year: workYear.value,
            fileSize: fileSize.value || '64.2 MB',
        });
        setTimeout(() => {
            depositSuccess.value = false;
            step.value = 1;
            workTitle.value = '';
            workDesc.value = '';
            fileName.value = '';
            emit('update:open', false);
        }, 1800);
    }, 1200);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="max-w-xl overflow-hidden rounded-2xl border border-onda-blue-500/30 bg-card p-0 shadow-2xl"
        >
            <!-- Modal Header -->
            <div class="border-b border-border/80 bg-muted/30 px-6 py-4">
                <DialogTitle class="text-base font-bold text-foreground">
                    {{ t('dashboard.newModal.title') }}
                </DialogTitle>
                <DialogDescription class="mt-0.5 text-xs text-muted-foreground">
                    {{ t('dashboard.newModal.subtitle') }}
                </DialogDescription>

                <!-- Steps indicator -->
                <div
                    class="mt-4 flex items-center justify-between text-xs font-semibold"
                >
                    <span
                        :class="
                            step >= 1
                                ? 'text-onda-blue-600 dark:text-onda-blue-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('dashboard.newModal.step1') }}
                    </span>
                    <div class="mx-3 h-0.5 flex-1 bg-muted">
                        <div
                            class="h-full bg-onda-blue-600 transition-all dark:bg-onda-blue-400"
                            :style="{
                                width:
                                    step === 1
                                        ? '0%'
                                        : step === 2
                                          ? '50%'
                                          : '100%',
                            }"
                        />
                    </div>
                    <span
                        :class="
                            step >= 2
                                ? 'text-onda-blue-600 dark:text-onda-blue-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('dashboard.newModal.step2') }}
                    </span>
                    <div class="mx-3 h-0.5 flex-1 bg-muted">
                        <div
                            class="h-full bg-onda-blue-600 transition-all dark:bg-onda-blue-400"
                            :style="{ width: step === 3 ? '100%' : '0%' }"
                        />
                    </div>
                    <span
                        :class="
                            step >= 3
                                ? 'text-onda-blue-600 dark:text-onda-blue-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('dashboard.newModal.step3') }}
                    </span>
                </div>
            </div>

            <!-- Modal Content -->
            <div class="space-y-5 p-6">
                <!-- Success Notification Screen -->
                <div v-if="depositSuccess" class="space-y-3 py-8 text-center">
                    <div
                        class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400"
                    >
                        <CheckCircle2 class="size-8 animate-bounce" />
                    </div>
                    <h3 class="text-base font-bold text-foreground">
                        Dépôt Enregistré & Horodaté avec Succès !
                    </h3>
                    <p class="mx-auto max-w-sm text-xs text-muted-foreground">
                        Votre attestation numérique officielle a été générée et
                        validée sous l'Ordonnance 03-05.
                    </p>
                </div>

                <!-- Step 1: Category Picker -->
                <div v-else-if="step === 1" class="space-y-3">
                    <Label class="text-xs font-semibold text-foreground">
                        Sélectionnez la catégorie de votre œuvre :
                    </Label>
                    <div class="grid grid-cols-2 gap-3">
                        <button
                            v-for="cat in categories"
                            :key="cat.id"
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-3 rounded-xl border p-3.5 text-start transition-all',
                                selectedCategory === cat.id
                                    ? 'border-onda-blue-600 bg-onda-blue-500/10 font-semibold text-foreground dark:border-onda-blue-400 dark:bg-onda-blue-500/20'
                                    : 'border-border bg-muted/20 text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                            ]"
                            @click="selectedCategory = cat.id"
                        >
                            <component
                                :is="cat.icon"
                                class="size-5 shrink-0 text-onda-blue-600 dark:text-onda-blue-400"
                            />
                            <span class="text-xs">{{ t(cat.titleKey) }}</span>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Metadata Fields -->
                <div v-else-if="step === 2" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label
                            for="workTitle"
                            class="text-xs font-semibold text-foreground"
                        >
                            {{ t('dashboard.newModal.workTitleLabel') }}
                        </Label>
                        <Input
                            id="workTitle"
                            v-model="workTitle"
                            type="text"
                            :placeholder="
                                t('dashboard.newModal.workTitlePlaceholder')
                            "
                            class="input-premium h-10 rounded-xl text-xs"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <Label
                                for="workYear"
                                class="text-xs font-semibold text-foreground"
                            >
                                {{ t('dashboard.newModal.yearLabel') }}
                            </Label>
                            <Input
                                id="workYear"
                                v-model="workYear"
                                type="number"
                                class="input-premium h-10 rounded-xl text-xs"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label class="text-xs font-semibold text-foreground"
                                >Domaine</Label
                            >
                            <div
                                class="flex h-10 items-center rounded-xl border border-border bg-muted/40 px-3 text-xs font-semibold text-foreground capitalize"
                            >
                                {{ selectedCategory }}
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label
                            for="workDesc"
                            class="text-xs font-semibold text-foreground"
                        >
                            {{ t('dashboard.newModal.descLabel') }}
                        </Label>
                        <textarea
                            id="workDesc"
                            v-model="workDesc"
                            rows="3"
                            :placeholder="
                                t('dashboard.newModal.descPlaceholder')
                            "
                            class="w-full rounded-xl border border-input bg-background p-3 text-xs shadow-xs focus:border-onda-blue-600 focus:outline-hidden dark:focus:border-onda-blue-400"
                        />
                    </div>
                </div>

                <!-- Step 3: File Upload Dropzone -->
                <div v-else-if="step === 3" class="space-y-4">
                    <Label class="text-xs font-semibold text-foreground">
                        {{ t('dashboard.newModal.uploadLabel') }}
                    </Label>

                    <label
                        class="flex cursor-pointer flex-col items-center justify-center space-y-2 rounded-2xl border-2 border-dashed border-border/80 bg-muted/20 p-6 text-center transition-all hover:border-onda-blue-500/60 hover:bg-muted/40"
                    >
                        <CloudUpload
                            class="size-10 animate-pulse text-onda-blue-600 dark:text-onda-blue-400"
                        />
                        <span class="text-xs font-semibold text-foreground">
                            {{
                                fileName ||
                                'Cliquez pour sélectionner le fichier Master'
                            }}
                        </span>
                        <span class="text-[11px] text-muted-foreground">
                            {{
                                fileName
                                    ? `${fileSize} • Prêt pour empreinte SHA-256`
                                    : t('dashboard.newModal.uploadHint')
                            }}
                        </span>
                        <input
                            type="file"
                            class="hidden"
                            @change="handleFileSelect"
                        />
                    </label>

                    <div
                        class="flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-emerald-600 dark:text-emerald-400"
                    >
                        <ShieldCheck class="size-4 shrink-0" />
                        <span
                            >Chiffrement et horodatage souverain immédiat à la
                            réception.</span
                        >
                    </div>
                </div>
            </div>

            <!-- Modal Footer Controls -->
            <div
                v-if="!depositSuccess"
                class="flex items-center justify-between border-t border-border/80 bg-muted/20 px-6 py-4"
            >
                <Button
                    variant="outline"
                    size="sm"
                    class="cursor-pointer rounded-xl text-xs"
                    :disabled="isSubmitting"
                    @click="step > 1 ? step-- : emit('update:open', false)"
                >
                    {{
                        step > 1
                            ? t('dashboard.table.prev')
                            : t('dashboard.newModal.cancel')
                    }}
                </Button>

                <Button
                    size="sm"
                    class="h-9 cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 px-4 text-xs font-semibold text-white shadow-xs hover:from-onda-blue-700 hover:to-onda-blue-800"
                    :disabled="isSubmitting || (step === 2 && !workTitle)"
                    @click="handleNext"
                >
                    <Spinner
                        v-if="isSubmitting"
                        class="me-1 size-3.5 text-white"
                    />
                    <span>
                        {{
                            isSubmitting
                                ? t('dashboard.newModal.submitting')
                                : step === 3
                                  ? t('dashboard.newModal.submitBtn')
                                  : t('dashboard.table.next')
                        }}
                    </span>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
