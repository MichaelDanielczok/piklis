<script setup>
/**
 * Einstellungen (aus dem Benutzermenü): Darstellung (dieselbe Wahl wie im
 * Benutzermenü) und was offline auf dem Gerät liegt (Listen, Produkte,
 * Bilder, wartende Änderungen, Größe).
 *
 * Nur im Entwicklungsserver: Schalter „Offline simulieren“ (`api/index.js`),
 * damit sich Offline-Einkauf und Abgleich im Browser testen lassen.
 */
import { computed, ref, useId } from 'vue';
import { onIonViewWillEnter } from '@ionic/vue';
import AppPage from '@/components/layout/AppPage.vue';
import ThemeSwitch from '@/components/ui/ThemeSwitch.vue';
import { localStore } from '@/api/localStore.js';
import { useConnection } from '@/composables/useConnection.js';
import { useLists } from '@/composables/useLists.js';
import { imageCacheSize } from '@/utils/offlineImages.js';
import { formatNumber, t } from '@/i18n/index.js';

const headingId = useId();
const offlineHeadingId = useId();
const devHeadingId = useId();
const switchId = useId();

const { simulatedOffline, setSimulatedOffline, canSimulate } = useConnection();
const { pendingOps } = useLists();

const sizes = ref(null);
const images = ref(null);

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) {
        return formatNumber(Math.max(1, Math.round(bytes / 1024))) + ' KB';
    }

    return formatNumber(Math.round(bytes / 1024 / 102.4) / 10) + ' MB';
}

const summary = computed(() => {
    if (!sizes.value) {
        return '';
    }

    const parts = [
        t('settings.offlineLists', { count: sizes.value.listCount }),
        t('settings.offlineProducts', { count: sizes.value.productCount }),
        images.value ? t('settings.offlineImages', { count: images.value.count }) : t('settings.offlineNoImages')
    ];
    const bytes = sizes.value.lists + sizes.value.catalog + sizes.value.queue + sizes.value.other + (images.value?.bytes ?? 0);

    return parts.join(' · ') + ' · ' + t('settings.offlineSize', { size: formatBytes(bytes) });
});

async function measure() {
    try {
        [sizes.value, images.value] = await Promise.all([localStore.sizes(), imageCacheSize()]);
    } catch (error) {
        console.warn('Offline-Speicher nicht gemessen', error);
    }
}

onIonViewWillEnter(measure);

function toggleOffline(event) {
    setSimulatedOffline(event.target.checked);
}
</script>

<template>
    <AppPage :title="t('settings.title')" back width="narrow">
        <div class="view-body">
            <section class="settings__section" :aria-labelledby="headingId">
                <h2 :id="headingId" class="settings__heading">{{ t('settings.theme') }}</h2>
                <ThemeSwitch :label="t('settings.theme')" />
                <p class="settings__hint">{{ t('settings.themeHint') }}</p>
            </section>

            <section class="settings__section" :aria-labelledby="offlineHeadingId">
                <h2 :id="offlineHeadingId" class="settings__heading">{{ t('settings.offline') }}</h2>
                <p class="settings__hint">{{ t('settings.offlineHint') }}</p>
                <!-- Höhe steht schon vor dem Messen (eine Zeile), damit nichts springt -->
                <p class="settings__value">{{ summary || ' ' }}</p>
                <p v-if="pendingOps.length > 0" class="settings__value">{{ t('settings.offlineWaiting', { count: pendingOps.length }) }}</p>
            </section>

            <section v-if="canSimulate" class="settings__section" :aria-labelledby="devHeadingId">
                <h2 :id="devHeadingId" class="settings__heading">{{ t('settings.dev') }}</h2>
                <label class="settings__switch" :for="switchId">
                    <span>{{ t('settings.simulateOffline') }}</span>
                    <input
                        :id="switchId"
                        class="settings__toggle"
                        type="checkbox"
                        role="switch"
                        data-name="simulate-offline"
                        :checked="simulatedOffline"
                        @change="toggleOffline"
                    >
                </label>
                <p class="settings__hint">{{ t('settings.simulateOfflineHint') }}</p>
            </section>
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
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

.settings__value {
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-name);
}

.settings__switch {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    min-height: var(--tap-size);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
}

/* Schalter ohne Bibliothek: Kontrollkästchen als Schiene mit Knopf */
.settings__toggle {
    position: relative;
    flex: none;
    width: 3rem;
    height: 1.75rem;
    margin: 0;
    border-radius: 0.875rem;
    background: var(--color-surface-2);
    box-shadow: inset 0 0 0 1px var(--color-border);
    appearance: none;
    cursor: pointer;
    transition: background-color var(--duration-fast) var(--ease-out);
}

.settings__toggle::after {
    position: absolute;
    top: 0.1875rem;
    left: 0.1875rem;
    width: 1.375rem;
    height: 1.375rem;
    border-radius: 50%;
    background: var(--color-surface-1);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.25);
    content: '';
    transition: transform var(--duration-fast) var(--ease-out);
}

.settings__toggle:checked {
    background: var(--color-accent);
}

.settings__toggle:checked::after {
    transform: translateX(1.25rem);
}

.settings__toggle:focus-visible {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
}
</style>
