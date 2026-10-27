<script setup>
/**
 * Kopf jeder Ansicht im Stil des Designpakets: großer Titel (28 px) als <h1>,
 * optional mit Unterzeile, auf Unterseiten (Profil, Haushalt …) mit
 * Zurück-Knopf davor. Rechts Aktionen (Slot) und auf dem Handy das
 * Benutzerbild mit Menü; am Desktop sitzt das Menü unten in der Seitenleiste.
 * Scrollt mit dem Inhalt; die Statusleiste deckt AppPage ab.
 */
import { useIonRouter } from '@ionic/vue';
import { ChevronLeft } from '@lucide/vue';
import UserMenu from './UserMenu.vue';
import { navigateBack } from '@/composables/usePageTransition.js';
import { t } from '@/i18n/index.js';

defineProps({
    title: {
        type: String,
        required: true
    },
    subtitle: {
        type: String,
        default: ''
    },
    back: {
        type: Boolean,
        default: false
    }
});

const ionRouter = useIonRouter();
</script>

<template>
    <header class="app-header">
        <button
            v-if="back"
            type="button"
            class="app-header__back"
            :aria-label="t('common.back')"
            @click="navigateBack(ionRouter, '/')"
        >
            <ChevronLeft class="app-header__back-icon" aria-hidden="true" />
        </button>

        <div class="app-header__titles">
            <h1 class="app-header__title">{{ title }}</h1>
            <p v-if="subtitle" class="app-header__subtitle">{{ subtitle }}</p>
        </div>

        <div class="app-header__end">
            <slot name="actions" />
            <UserMenu class="app-header__user" placement="header" />
        </div>
    </header>
</template>

<style scoped>
.app-header {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-height: calc(var(--tap-size) + 2 * var(--space-4));
    padding: var(--space-4) var(--page-gutter) var(--space-2);
}

.app-header__back {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    margin-left: calc(-1 * var(--space-3));
    border-radius: 50%;
    color: var(--color-text-1);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .app-header__back:hover {
        background: var(--color-surface-2);
    }
}

.app-header__back:active {
    transform: scale(var(--press-scale));
}

.app-header__back-icon {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.app-header__titles {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.app-header__title {
    overflow: hidden;
    font-size: var(--font-size-h1);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h1);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.app-header__subtitle {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.app-header__end {
    display: flex;
    flex: none;
    align-items: center;
    gap: var(--space-2);
}

@media (min-width: 64em) {
    .app-header {
        padding-top: var(--space-8);
        padding-bottom: var(--space-4);
    }

    .app-header__user {
        display: none;
    }
}
</style>
