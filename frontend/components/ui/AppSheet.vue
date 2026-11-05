<script setup>
/**
 * Sheet von unten (Ionic-Modal als Sheet mit automatischer Höhe): Radius
 * 22 px oben, Griffmarke, Titel mit optionaler Unterzeile, runder
 * Schließen-Knopf (44 px), Scrim aus den Tokens. Schließt auch über Wischen
 * nach unten, Tippen daneben und Escape. Ab Tablet mittig und rundum gerundet.
 *
 * Kopf und Knöpfe bleiben stehen, nur der Inhalt scrollt, und zwar ohne
 * sichtbaren Balken; die Klasse `ion-content-scroll-host` sagt Ionics
 * Wisch-Geste, dass sie dort erst bei Scrollstand 0 greifen darf. Über die
 * Tastatur hebt iOS das Sheet selbst, indem es die ganze Seite verschiebt.
 *
 * v-model:open · Slots: default (Inhalt), footer (Knöpfe unten)
 * Emits: presented (Sheet steht, z. B. um ein Feld zu fokussieren)
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
const emit = defineEmits(['presented']);
const titleId = useId();
</script>

<template>
    <IonModal
        class="app-sheet"
        :is-open="open"
        :initial-breakpoint="1"
        :breakpoints="[0, 1]"
        :aria-labelledby="titleId"
        @did-present="emit('presented')"
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

            <div class="app-sheet__content ion-content-scroll-host">
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

/* ohne Flex hätte der Inhalt keine Höhengrenze und liefe unten aus dem Sheet */
.app-sheet::part(content) {
    display: flex;
    flex-direction: column;
}

.app-sheet::part(handle) {
    width: var(--sheet-handle-width);
    background: var(--color-border-strong);
}

.app-sheet__body {
    display: flex;
    flex: 0 1 auto;
    flex-direction: column;
    gap: var(--space-4);
    min-width: 0;
    min-height: 0;
    padding: var(--space-6) 0 calc(var(--space-4) + env(safe-area-inset-bottom, 0px));
    color: var(--color-text-1);
}

.app-sheet__head {
    display: flex;
    flex: none;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 0 var(--page-gutter);
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

/* Abstand innen statt am Sheet: sonst schnitte das Scrollen Fokusrahmen ab */
.app-sheet__content {
    flex: 0 1 auto;
    min-height: 0;
    overflow: hidden auto;
    overscroll-behavior: contain;
    padding: var(--space-1) var(--page-gutter);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
    scrollbar-width: none;
}

.app-sheet__content::-webkit-scrollbar {
    display: none;
}

.app-sheet__footer {
    display: grid;
    flex: none;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: var(--space-3);
    padding: var(--space-2) var(--page-gutter) 0;
}

@media (min-width: 45em) {
    .app-sheet {
        --width: var(--sheet-max-width);
    }
}
</style>
