<script setup>
/**
 * Einstellungen (aus dem Benutzermenü): vorerst nur die Darstellung, dieselbe
 * Wahl wie im Benutzermenü.
 */
import { useId } from 'vue';
import AppPage from '@/components/layout/AppPage.vue';
import ThemeSwitch from '@/components/ui/ThemeSwitch.vue';
import { t } from '@/i18n/index.js';

const headingId = useId();
</script>

<template>
    <AppPage :title="t('settings.title')" back width="narrow">
        <div class="view-body">
            <section class="settings__section" :aria-labelledby="headingId">
                <h2 :id="headingId" class="settings__heading">{{ t('settings.theme') }}</h2>
                <ThemeSwitch :label="t('settings.theme')" />
                <p class="settings__hint">{{ t('settings.themeHint') }}</p>
            </section>
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}

.settings__section {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.settings__heading {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.settings__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
