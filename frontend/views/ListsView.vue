<script setup>
/**
 * Listen: Startansicht. Platzhalter, bis Schritt 5 die Einkaufslisten bringt.
 */
import { ListChecks } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { formatDayHeading, t } from '@/i18n/index.js';

const today = formatDayHeading(Date.now());
</script>

<template>
    <AppPage :title="t('lists.title')" :subtitle="today" width="list">
        <div class="view-body">
            <EmptyState :icon="ListChecks" :title="t('lists.emptyTitle')" :text="t('lists.emptyText')" />
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}
</style>
