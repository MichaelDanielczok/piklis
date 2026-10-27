<script setup>
/**
 * Vorlagen: wiederkehrende Einkäufe. Platzhalter bis Schritt 12.
 */
import { Files } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { t } from '@/i18n/index.js';
</script>

<template>
    <AppPage :title="t('templates.title')" width="list">
        <div class="view-body">
            <EmptyState :icon="Files" :title="t('templates.emptyTitle')" :text="t('templates.emptyText')" />
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}
</style>
