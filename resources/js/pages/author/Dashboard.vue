<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ref } from 'vue';
import CertificateModal from '@/components/dashboard/CertificateModal.vue';
import DashboardHero from '@/components/dashboard/DashboardHero.vue';
import DashboardQuickActions from '@/components/dashboard/DashboardQuickActions.vue';
import DashboardRoyaltiesChart from '@/components/dashboard/DashboardRoyaltiesChart.vue';
import DashboardStats from '@/components/dashboard/DashboardStats.vue';
import DashboardWorksTable from '@/components/dashboard/DashboardWorksTable.vue';
import type { Work } from '@/components/dashboard/DashboardWorksTable.vue';
import NewDepositModal from '@/components/dashboard/NewDepositModal.vue';
import { useDashboardModal } from '@/composables/useDashboardModal';
import { dashboard } from '@/routes/author';

const { isDepositOpen, initialDepositCategory, openDeposit } =
    useDashboardModal();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const selectedWorkForCert = ref<Work | null>(null);
const isCertModalOpen = ref(false);

const handleViewCertificate = (work: Work) => {
    selectedWorkForCert.value = work;
    isCertModalOpen.value = true;
};

const handleSelectCategory = (category: string) => {
    openDeposit(category);
};

const handleOpenStatement = () => {
    // Smooth scroll down to Royalties breakdown
    const el = document.getElementById('royalties-section');

    if (el) {
        el.scrollIntoView({ behavior: 'smooth' });
    }
};

const handleDepositCreated = () => {
    // Show instant toast or reactive update
};

// Global Hotkey (⌘K / Ctrl+K)
useEventListener('keydown', (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openDeposit('music');
    }
});
</script>

<template>
    <Head title="Tableau de bord — Espace Auteur ONDA" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- 1. Hero Identity & Member Tier Header -->
        <DashboardHero
            @open-deposit="openDeposit('music')"
            @open-statement="handleOpenStatement"
        />

        <!-- 2. Real-Time KPI Stats & Progress Sparklines -->
        <DashboardStats />

        <!-- 3. Quick Multi-Domain Deposit Launchpad -->
        <DashboardQuickActions @select-category="handleSelectCategory" />

        <!-- 4. Works & Declarations Registry Table -->
        <DashboardWorksTable
            @view-certificate="handleViewCertificate"
            @open-deposit="openDeposit('music')"
        />

        <!-- 5. Royalties Distribution Analytics & Payout History -->
        <div id="royalties-section" class="pt-2">
            <DashboardRoyaltiesChart />
        </div>
    </div>

    <!-- Official Digital Certificate Modal Dialog -->
    <CertificateModal
        :work="selectedWorkForCert"
        :open="isCertModalOpen"
        @update:open="isCertModalOpen = $event"
    />

    <!-- New Work Deposit Multi-step Modal -->
    <NewDepositModal
        :open="isDepositOpen"
        :initial-category="initialDepositCategory"
        @update:open="isDepositOpen = $event"
        @deposit-created="handleDepositCreated"
    />
</template>
