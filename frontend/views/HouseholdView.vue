<script setup>
/**
 * Haushalt (aus dem Benutzermenü). Platzhalter bis Schritt 9.
 */
import { House } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { t } from '@/i18n/index.js';
</script>

<template>
    <AppPage :title="t('household.title')" back width="narrow">
        <div class="view-body">
            <EmptyState :icon="House" :title="t('household.emptyTitle')" :text="t('household.emptyText')" />
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}
</style>
