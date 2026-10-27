<script setup>
/**
 * Sheet von unten (Ionic-Modal als Sheet mit automatischer Höhe): Radius
 * 22 px oben, Griffmarke, Titel mit optionaler Unterzeile, runder
 * Schließen-Knopf (44 px), Scrim aus den Tokens. Schließt auch über Wischen
 * nach unten, Tippen daneben und Escape. Ab Tablet mittig und rundum gerundet.
 *
 * v-model:open · Slots: default (Inhalt), footer (Knöpfe unten)
 */
import { IonModal } from '@ionic/vue';
import { X } from '@lucide/vue';
import { useId } from 'vue';
import { t } from '@/i18n/index.js';

defineProps({
    title: {
        type: String,
        required: true
    },
    subtitle: {
        type: String,
        default: ''
    }
});

const open = defineModel('open', { type: Boolean, default: false });
const titleId = useId();
</script>

<template>
    <IonModal
        class="app-sheet"
        :is-open="open"
        :initial-breakpoint="1"
        :breakpoints="[0, 1]"
        :aria-labelledby="titleId"
        @did-dismiss="open = false"
    >
        <div class="app-sheet__body">
            <header class="app-sheet__head">
                <div class="app-sheet__titles">
                    <h2 :id="titleId" class="app-sheet__title">{{ title }}</h2>
                    <p v-if="subtitle" class="app-sheet__subtitle">{{ subtitle }}</p>
                </div>
                <button type="button" class="app-sheet__close" :aria-label="t('common.close')" @click="open = false">
                    <X class="app-sheet__close-icon" aria-hidden="true" />
                </button>
            </header>

            <div class="app-sheet__content">
                <slot />
            </div>

            <footer v-if="$slots.footer" class="app-sheet__footer">
                <slot name="footer" />
            </footer>
        </div>
    </IonModal>
</template>

<style scoped>
.app-sheet {
    --height: auto;
    --max-height: calc(100% - env(safe-area-inset-top, 0px) - var(--space-8));
    --border-radius: var(--radius-lg);
    --background: var(--color-surface-1);
    --backdrop-opacity: 1;
}

.app-sheet::part(handle) {
    width: var(--sheet-handle-width);
    background: var(--color-border-strong);
}

.app-sheet__body {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    max-height: inherit;
    overflow-y: auto;
    padding: var(--space-6) var(--page-gutter) calc(var(--space-4) + env(safe-area-inset-bottom, 0px));
    color: var(--color-text-1);
}

.app-sheet__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
}

.app-sheet__titles {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
    padding-top: var(--space-2);
}

.app-sheet__title {
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}

.app-sheet__subtitle {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.app-sheet__close {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    background: var(--color-surface-2);
    color: var(--color-text-1);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.app-sheet__close:active {
    transform: scale(var(--press-scale));
}

@media (hover: hover) {
    .app-sheet__close:hover {
        background: var(--color-surface-3);
    }
}

.app-sheet__close-icon {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.app-sheet__content {
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.app-sheet__footer {
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: var(--space-3);
    padding-top: var(--space-2);
}

@media (min-width: 45em) {
    .app-sheet {
        --width: var(--sheet-max-width);
    }
}
</style>
