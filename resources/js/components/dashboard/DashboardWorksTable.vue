<script setup lang="ts">
import {
    Award,
    BookOpen,
    Check,
    Clapperboard,
    CodeXml,
    Copy,
    FileCheck2,
    Music,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ActionButton } from '@/components/ui/action';
import {
    DataTable,
    DataTableToolbar,
    DataTableSearch,
    DataTableFilterPills,
    DataTableStatusBadge,
    DataTablePagination,
    DataTableEmpty,
} from '@/components/ui/data-table';
import { Tabs } from '@/components/ui/tabs';
import type { TabItem } from '@/components/ui/tabs';

const { t, locale } = useI18n();

export interface Work {
    id: string;
    title: string;
    titleAr: string;
    category: 'music' | 'literature' | 'cinema' | 'software';
    reference: string;
    date: string;
    year: number;
    hash: string;
    status: 'approved' | 'pending' | 'draft' | 'distributed';
    fileSize: string;
    authorName: string;
}

const works = ref<Work[]>([
    {
        id: '1',
        title: 'Symphonie des Aurès (Opus 4)',
        titleAr: 'سيمفونية الأوراس (المصنف 4)',
        category: 'music',
        reference: 'DZ-2026-MUS-0814',
        date: '24 Août 2026',
        year: 2026,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        status: 'approved',
        fileSize: '142.8 MB (FLAC Master)',
        authorName: 'Mohamed Benali',
    },
    {
        id: '2',
        title: 'Récits de la Casbah : Mémoire Vivante',
        titleAr: 'حكايات القصبة: ذاكرة حية',
        category: 'literature',
        reference: 'DZ-2026-LIT-0791',
        date: '18 Août 2026',
        year: 2026,
        hash: '8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4',
        status: 'approved',
        fileSize: '18.4 MB (PDF Archive)',
        authorName: 'Mohamed Benali',
    },
    {
        id: '3',
        title: "L'Épopée du Tassili (Documentaire 4K)",
        titleAr: 'ملحمة الطاسيلي (وثائقي 4K)',
        category: 'cinema',
        reference: 'DZ-2026-AV-0652',
        date: '12 Août 2026',
        year: 2026,
        hash: 'ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb',
        status: 'pending',
        fileSize: '4.2 GB (ProRes Master)',
        authorName: 'Mohamed Benali',
    },
    {
        id: '4',
        title: 'Algorithme Numismatique DZ-Auth v2',
        titleAr: 'خوارزمية المصادقة النقدية DZ-Auth v2',
        category: 'software',
        reference: 'DZ-2026-DEV-0410',
        date: '04 Août 2026',
        year: 2026,
        hash: '4e07408562bedb8b60ce05c1decfe3ad16b72230967de01f640b7e4729b49fce',
        status: 'approved',
        fileSize: '32.1 MB (Source Archive)',
        authorName: 'Mohamed Benali',
    },
    {
        id: '5',
        title: 'Qassida Al-Watan : Suite Vocale et Cordes',
        titleAr: 'قصيدة الوطن: متتالية صوتية ووتريات',
        category: 'music',
        reference: 'DZ-2026-MUS-0389',
        date: '28 Juillet 2026',
        year: 2026,
        hash: '4b227777d4dd1fc61c6f884f48641d02b4d121d3fd328cb08b5531fcacdabf8a',
        status: 'distributed',
        fileSize: '95.2 MB (WAV 24bit)',
        authorName: 'Mohamed Benali',
    },
    {
        id: '6',
        title: 'Gouvernance Numérique et Propriété Intellectuelle',
        titleAr: 'الحوكمة الرقمية والملكية الفكرية',
        category: 'literature',
        reference: 'DZ-2026-LIT-0219',
        date: '15 Juillet 2026',
        year: 2026,
        hash: 'ef2d127de37b942baad06145e54b0c619a1f22327b2ebbcfbec78f5564afe39d',
        status: 'draft',
        fileSize: '12.0 MB (Manuscript)',
        authorName: 'Mohamed Benali',
    },
]);

const searchQuery = ref('');
const selectedStatus = ref<string>('all');
const copiedHashId = ref<string | null>(null);

const emit = defineEmits<{
    (e: 'view-certificate', work: Work): void;
    (e: 'open-deposit'): void;
}>();

const statusTabs = computed<TabItem[]>(() => [
    {
        value: 'all',
        label: t('dashboard.table.allStatus'),
        count: works.value.length,
    },
    {
        value: 'approved',
        label: t('dashboard.table.statusApproved'),
        count: works.value.filter((w) => w.status === 'approved').length,
    },
    {
        value: 'pending',
        label: t('dashboard.table.statusPending'),
        count: works.value.filter((w) => w.status === 'pending').length,
    },
    {
        value: 'distributed',
        label: t('dashboard.table.statusDistributed'),
        count: works.value.filter((w) => w.status === 'distributed').length,
    },
]);

const filteredWorks = computed(() => {
    return works.value.filter((item) => {
        const matchesSearch =
            searchQuery.value.trim() === '' ||
            item.title
                .toLowerCase()
                .includes(searchQuery.value.toLowerCase()) ||
            item.titleAr.includes(searchQuery.value) ||
            item.reference
                .toLowerCase()
                .includes(searchQuery.value.toLowerCase()) ||
            item.hash.toLowerCase().includes(searchQuery.value.toLowerCase());

        const matchesStatus =
            selectedStatus.value === 'all' ||
            item.status === selectedStatus.value;

        return matchesSearch && matchesStatus;
    });
});

const copyHash = async (id: string, hash: string) => {
    try {
        await navigator.clipboard.writeText(hash);
        copiedHashId.value = id;
        setTimeout(() => {
            copiedHashId.value = null;
        }, 2000);
    } catch {
        // clipboard fallback
    }
};

const getCategoryIcon = (category: Work['category']) => {
    switch (category) {
        case 'music':
            return Music;
        case 'literature':
            return BookOpen;
        case 'cinema':
            return Clapperboard;
        case 'software':
            return CodeXml;
    }
};

const getCategoryBadgeClass = (category: Work['category']) => {
    switch (category) {
        case 'music':
            return 'bg-blue-500/10 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 border-blue-500/30';
        case 'literature':
            return 'bg-amber-500/10 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300 border-amber-500/30';
        case 'cinema':
            return 'bg-purple-500/10 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300 border-purple-500/30';
        case 'software':
            return 'bg-teal-500/10 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300 border-teal-500/30';
    }
};

const activeFilters = computed(() => {
    const pills: { key: string; label: string; value: string }[] = [];

    if (searchQuery.value.trim() !== '') {
        pills.push({
            key: 'search',
            label: t('common.search', 'Recherche'),
            value: `"${searchQuery.value.trim()}"`,
        });
    }

    if (selectedStatus.value !== 'all') {
        const found = statusTabs.value.find(
            (tab) => tab.value === selectedStatus.value,
        );
        pills.push({
            key: 'status',
            label: t('dashboard.table.colStatus', 'Statut'),
            value: found ? found.label : selectedStatus.value,
        });
    }

    return pills;
});

const removeFilter = (key: string) => {
    if (key === 'search') {
        searchQuery.value = '';
    } else if (key === 'status') {
        selectedStatus.value = 'all';
    }
};

const clearFilters = () => {
    searchQuery.value = '';
    selectedStatus.value = 'all';
};

const getStatusLabel = (status: Work['status']) => {
    switch (status) {
        case 'approved':
            return t('dashboard.table.statusApproved');
        case 'pending':
            return t('dashboard.table.statusPending');
        case 'distributed':
            return t('dashboard.table.statusDistributed');
        case 'draft':
        default:
            return t('dashboard.table.statusDraft');
    }
};
</script>

<template>
    <DataTable>
        <template #toolbar>
            <DataTableToolbar
                :title="t('dashboard.table.title')"
                :subtitle="t('dashboard.table.subtitle')"
                :count="filteredWorks.length"
                :icon="FileCheck2"
            >
                <template #search>
                    <DataTableSearch
                        v-model="searchQuery"
                        :placeholder="t('dashboard.table.searchPlaceholder')"
                        class="w-full sm:w-72"
                    />
                </template>

                <template #tabs>
                    <Tabs
                        v-model="selectedStatus"
                        :tabs="statusTabs"
                        size="sm"
                        class="overflow-x-auto"
                    />
                </template>

                <template #filter-pills>
                    <DataTableFilterPills
                        :filters="activeFilters"
                        @remove="removeFilter"
                        @clear-all="clearFilters"
                    />
                </template>
            </DataTableToolbar>
        </template>

        <!-- Empty Filter Results -->
        <DataTableEmpty
            v-if="filteredWorks.length === 0"
            :title="t('dashboard.table.noWorks')"
            :has-active-filters="
                Boolean(searchQuery.trim() || selectedStatus !== 'all')
            "
            @clear-filters="clearFilters"
        />

        <!-- Responsive Table with Index Column -->
        <table v-else class="w-full border-collapse text-start text-xs">
            <thead>
                <tr
                    class="border-b border-border/70 bg-muted/40 font-semibold text-muted-foreground"
                >
                    <th class="w-12 px-3 py-3 text-center font-medium">#</th>
                    <th class="px-4 py-3 text-start font-medium">
                        {{ t('dashboard.table.colTitle') }}
                    </th>
                    <th
                        class="hidden px-4 py-3 text-start font-medium md:table-cell"
                    >
                        {{ t('dashboard.table.colRef') }}
                    </th>
                    <th
                        class="hidden px-4 py-3 text-start font-medium sm:table-cell"
                    >
                        {{ t('dashboard.table.colDate') }}
                    </th>
                    <th
                        class="hidden px-4 py-3 text-start font-medium lg:table-cell"
                    >
                        {{ t('dashboard.table.colHash') }}
                    </th>
                    <th class="px-4 py-3 text-start font-medium">
                        {{ t('dashboard.table.colStatus') }}
                    </th>
                    <th class="px-4 py-3 text-end font-medium">
                        {{ t('dashboard.table.colActions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
                <tr
                    v-for="(work, index) in filteredWorks"
                    :key="work.id"
                    class="group transition-colors duration-150 hover:bg-muted/40"
                >
                    <!-- Index Column -->
                    <td
                        class="w-12 px-3 py-3.5 text-center text-xs font-medium text-muted-foreground"
                    >
                        {{ index + 1 }}
                    </td>

                    <!-- Title & Domain -->
                    <td class="px-4 py-3.5">
                        <div class="flex items-center gap-3">
                            <div
                                :class="[
                                    'flex size-9 shrink-0 items-center justify-center rounded-lg border',
                                    getCategoryBadgeClass(work.category),
                                ]"
                            >
                                <component
                                    :is="getCategoryIcon(work.category)"
                                    :stroke-width="1.75"
                                    class="size-4"
                                />
                            </div>
                            <div class="min-w-0 space-y-0.5">
                                <p
                                    class="max-w-[220px] truncate font-semibold text-foreground sm:max-w-xs md:max-w-md"
                                >
                                    {{
                                        locale === 'ar'
                                            ? work.titleAr
                                            : work.title
                                    }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ work.fileSize }} • {{ work.year }}
                                </p>
                            </div>
                        </div>
                    </td>

                    <!-- Reference Number -->
                    <td class="hidden px-4 py-3.5 md:table-cell">
                        <span
                            class="rounded-md bg-muted/60 px-2 py-1 font-mono text-xs font-medium text-foreground"
                        >
                            {{ work.reference }}
                        </span>
                    </td>

                    <!-- Date -->
                    <td
                        class="hidden px-4 py-3.5 whitespace-nowrap text-muted-foreground sm:table-cell"
                    >
                        {{ work.date }}
                    </td>

                    <!-- SHA-256 Checksum (Monospace Technical Value) -->
                    <td class="hidden px-4 py-3.5 lg:table-cell">
                        <button
                            type="button"
                            class="flex cursor-pointer items-center gap-1.5 rounded-md bg-muted/40 px-2 py-1 font-mono text-[11px] text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring"
                            :title="work.hash"
                            @click="copyHash(work.id, work.hash)"
                        >
                            <span class="max-w-[120px] truncate"
                                >{{ work.hash.slice(0, 10) }}…{{
                                    work.hash.slice(-6)
                                }}</span
                            >
                            <component
                                :is="copiedHashId === work.id ? Check : Copy"
                                :stroke-width="1.75"
                                :class="[
                                    'size-3 shrink-0',
                                    copiedHashId === work.id
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground',
                                ]"
                            />
                        </button>
                    </td>

                    <!-- Flowbite-inspired Status Badge with dot -->
                    <td class="px-4 py-3.5 whitespace-nowrap">
                        <DataTableStatusBadge
                            :status="work.status"
                            :label="getStatusLabel(work.status)"
                        />
                    </td>

                    <!-- Actions -->
                    <td class="px-4 py-3.5 text-end whitespace-nowrap">
                        <div class="flex items-center justify-end gap-1.5">
                            <ActionButton
                                action="view"
                                size="sm"
                                :label="t('dashboard.table.viewCert')"
                                @click="emit('view-certificate', work)"
                            />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Table Footer Pagination bar -->
        <template #pagination>
            <DataTablePagination
                :from="filteredWorks.length > 0 ? 1 : 0"
                :to="filteredWorks.length"
                :total="filteredWorks.length"
            />
        </template>
    </DataTable>
</template>
