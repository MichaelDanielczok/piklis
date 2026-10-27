<script setup>
/**
 * Produkte: gemeinsamer Katalog. Platzhalter bis Schritt 3.
 */
import { LayoutGrid } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { t } from '@/i18n/index.js';
</script>

<template>
    <AppPage :title="t('products.title')">
        <div class="view-body">
            <EmptyState :icon="LayoutGrid" :title="t('products.emptyTitle')" :text="t('products.emptyText')" />
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}
</style>
