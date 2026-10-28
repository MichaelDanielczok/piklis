<script setup>
/**
 * Zeile einer Einkaufsliste mit drei getrennten Bedienflächen:
 *
 * 1. Ziehgriff (nur offen): Sortieren nur hier. 32 px breit über die ganze
 *    Zeilenhöhe (Designpaket 28 × 48; breiter und höher, weil es so am Handy
 *    sicherer zu treffen ist und auf dem 4-px-Raster liegt).
 * 2. Details: Bild, Name (max. 2 Zeilen), Notiz bzw. Status, Menge rechts.
 *    Tippen öffnet das Produktdetail (`details`).
 * 3. Abhaken-Zone 60 px breit über die ganze Zeilenhöhe hinter einer
 *    Trennlinie, Ring 28 px (`toggle`). Bei „nicht bekommen“ steht dort der
 *    Knopf zum Übernehmen in eine andere Liste (`transfer`).
 *
 * Zustände (status): open · done (Haken gefüllt, durchgestrichen, kein Griff) ·
 * notGot (Kreuz und Text „nicht bekommen“). Unabhängig davon `unsynced`:
 * Wolke vor der Notiz bzw. Text „noch nicht übertragen“.
 * Status nie nur über Farbe: immer Symbol und Text dazu.
 *
 * Sortieren selbst (Ziehen, Platzhalter) übernimmt die Liste
 * (`useDragSort`): Der Griff meldet `handle-pointerdown`, mit der Tastatur
 * (Pfeil hoch/runter am Griff) `move` mit -1 bzw. 1.
 *
 * Wischen nach links (`swipe`: `{ label, variant }`, `useRowSwipe`) legt eine
 * Aktion frei, z. B. „Nicht bekommen“; Antippen oder weites Wischen meldet
 * `swipe-action`. Dieselbe Aktion gibt es im Produkt im Listenkontext.
 * `transferredTo`: Name der Liste, in die ein nicht bekommenes Produkt
 * übernommen wurde. `readonly` (abgeschlossene Liste): Zustand nur als
 * Symbol, ohne Griff und ohne Abhaken.
 */
import { computed, useId, useTemplateRef } from 'vue';
import { ArrowRight, Check, CloudUpload, GripVertical, RotateCcw, X } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import QuantityLabel from './QuantityLabel.vue';
import { useRowSwipe } from '@/composables/useRowSwipe.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    name: {
        type: String,
        required: true
    },
    note: {
        type: String,
        default: ''
    },
    amount: {
        type: Number,
        default: 1
    },
    unit: {
        type: String,
        default: null
    },
    imageUrl: {
        type: String,
        default: null
    },
    icon: {
        type: String,
        default: undefined
    },
    status: {
        type: String,
        default: 'open',
        validator: (value) => ['open', 'done', 'notGot'].includes(value)
    },
    unsynced: {
        type: Boolean,
        default: false
    },
    transferredTo: {
        type: String,
        default: null
    },
    readonly: {
        type: Boolean,
        default: false
    },
    swipe: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['details', 'toggle', 'transfer', 'handle-pointerdown', 'move', 'swipe-action']);

const handleHintId = useId();
const isDone = computed(() => props.status === 'done');
const isNotGot = computed(() => props.status === 'notGot');

// '' = übernommen in eine Liste, die hier nicht (mehr) bekannt ist
const notGotText = computed(() => {
    if (props.transferredTo === null) {
        return t('row.notGot');
    }

    return props.transferredTo ? t('row.notGotTransferred', { list: props.transferredTo }) : t('row.notGotTransferredElsewhere');
});

const rootRef = useTemplateRef('root');
const actionRef = useTemplateRef('action');
const { offset, dragging, runAction, listeners, onClickCapture } = useRowSwipe({
    root: rootRef,
    action: actionRef,
    enabled: () => props.swipe !== null && !props.readonly,
    onAction: () => emit('swipe-action')
});
</script>

<template>
    <li
        ref="root"
        class="list-row"
        :class="{
            'list-row--done': isDone,
            'list-row--not-got': isNotGot,
            'list-row--readonly': readonly,
            'list-row--swiped': offset !== 0
        }"
        v-on="listeners"
        @click.capture="onClickCapture"
    >
        <!-- Kurzweg für den Finger; Tastatur und Bildschirmleser nutzen das Produkt im Listenkontext -->
        <div v-if="swipe && !readonly" class="list-row__swipe-zone" aria-hidden="true">
            <button
                ref="action"
                type="button"
                tabindex="-1"
                class="list-row__swipe-action"
                :class="'list-row__swipe-action--' + (swipe.variant ?? 'notgot')"
                data-name="swipe-action"
                @click="runAction"
            >
                <RotateCcw v-if="swipe.variant === 'accent'" class="list-row__swipe-icon" />
                <X v-else class="list-row__swipe-icon" />
                <span>{{ swipe.label }}</span>
            </button>
        </div>

        <div
            class="list-row__content"
            :class="{ 'is-dragging': dragging }"
            :style="offset !== 0 ? { transform: `translateX(${offset}px)` } : undefined"
        >
            <div class="list-row__handle-zone">
                <button
                    v-if="status === 'open' && !readonly"
                    type="button"
                    class="list-row__handle"
                    data-name="handle"
                    :aria-label="t('row.handle', { name })"
                    :aria-describedby="handleHintId"
                    @pointerdown="emit('handle-pointerdown', $event)"
                    @keydown.up.prevent="emit('move', -1)"
                    @keydown.down.prevent="emit('move', 1)"
                >
                    <GripVertical class="list-row__handle-icon" aria-hidden="true" />
                    <span :id="handleHintId" class="visually-hidden">{{ t('row.handleHint') }}</span>
                </button>
            </div>

            <button type="button" class="list-row__details" @click="emit('details')">
                <ProductImage :image-url="imageUrl" :icon="icon" :muted="isDone" />

                <span class="list-row__text">
                    <span class="list-row__name">{{ name }}</span>

                    <span v-if="isDone" class="list-row__meta list-row__meta--done">
                        <Check class="list-row__meta-icon" aria-hidden="true" />
                        {{ t('row.done') }}
                    </span>
                    <span v-else-if="isNotGot" class="list-row__meta list-row__meta--not-got">
                        <X class="list-row__meta-icon" aria-hidden="true" />
                        <span class="list-row__status-text">
                            {{ notGotText }}
                        </span>
                    </span>
                    <span v-else-if="unsynced" class="list-row__meta list-row__meta--unsynced">
                        <CloudUpload class="list-row__meta-icon" aria-hidden="true" />
                        <template v-if="note">
                            <span class="visually-hidden">{{ t('row.unsynced') }}:</span>
                            <span class="list-row__note">{{ note }}</span>
                        </template>
                        <span v-else class="list-row__status-text">{{ t('row.unsynced') }}</span>
                    </span>
                    <span v-else-if="note" class="list-row__meta">
                        <span class="list-row__note">{{ note }}</span>
                    </span>
                </span>

                <QuantityLabel class="list-row__quantity" :amount="amount" :unit="unit" />
            </button>

            <div class="list-row__check-zone">
                <span v-if="readonly" class="list-row__check list-row__check--static" aria-hidden="true">
                    <span v-if="isNotGot" class="list-row__transfer">
                        <X class="list-row__transfer-icon" />
                    </span>
                    <span v-else class="list-row__ring">
                        <Check v-if="isDone" class="list-row__ring-icon" />
                    </span>
                </span>
                <button
                    v-else-if="isNotGot"
                    type="button"
                    class="list-row__check list-row__check--transfer"
                    :aria-label="t('row.transfer', { name })"
                    @click="emit('transfer')"
                >
                    <span class="list-row__transfer">
                        <ArrowRight class="list-row__transfer-icon" aria-hidden="true" />
                    </span>
                </button>
                <button
                    v-else
                    type="button"
                    class="list-row__check"
                    :aria-label="isDone ? t('row.uncheck', { name }) : t('row.check', { name })"
                    :aria-pressed="isDone"
                    @click="emit('toggle')"
                >
                    <span class="list-row__ring">
                        <Check v-if="isDone" class="list-row__ring-icon" aria-hidden="true" />
                    </span>
                </button>
            </div>
        </div>
    </li>
</template>

<style scoped>
.list-row {
    position: relative;
    display: flex;
    min-height: var(--row-min-height);
    background: var(--color-surface-1);
}

.list-row + .list-row {
    border-top: 1px solid var(--color-border-subtle);
}

.list-row__content {
    position: relative;
    z-index: 1;
    display: flex;
    flex: 1;
    align-items: stretch;
    min-width: 0;
    background: var(--color-surface-1);
    touch-action: pan-y;
    transition: transform var(--duration-medium) var(--ease-out);
}

.list-row__content.is-dragging {
    transition: none;
}

.list-row--done,
.list-row--done .list-row__content {
    background: var(--color-surface-done);
}

/* Wisch-Aktion hinter der Zeile, rechts */
.list-row__swipe-zone {
    position: absolute;
    inset: 0;
    display: flex;
    justify-content: flex-end;
    visibility: hidden;
}

.list-row--swiped .list-row__swipe-zone {
    visibility: visible;
}

.list-row__swipe-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-1);
    width: var(--swipe-action-width);
    padding: var(--space-2);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-note);
    text-align: center;
}

.list-row__swipe-action--notgot {
    background: var(--color-notgot);
    color: var(--color-on-notgot);
}

.list-row__swipe-action--accent {
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.list-row__swipe-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    stroke-width: 2.5;
}

@media (prefers-reduced-motion: reduce) {
    .list-row__content {
        transition: none;
    }
}

/* 1 · Ziehgriff */
.list-row__handle-zone {
    display: flex;
    flex: none;
    width: var(--handle-width);
}

.list-row__handle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    color: var(--color-handle);
    cursor: grab;
    touch-action: none;
}

.list-row__handle-icon {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

/* 2 · Details */
.list-row__details {
    display: flex;
    flex: 1;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
    padding: var(--space-2) var(--space-3) var(--space-2) 0;
    text-align: left;
}

.list-row__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0;
    min-width: 0;
}

.list-row__name {
    display: -webkit-box;
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
    hyphens: auto;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
}

.list-row--done .list-row__name {
    color: var(--color-text-2);
    text-decoration: line-through;
}

.list-row__meta {
    display: flex;
    align-items: flex-start;
    gap: var(--space-1);
    min-width: 0;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.list-row__meta--done {
    color: var(--color-done);
    font-weight: var(--font-weight-semibold);
}

.list-row__meta--not-got {
    color: var(--color-notgot);
    font-weight: var(--font-weight-semibold);
}

.list-row__meta--unsynced {
    color: var(--color-unsynced);
}

.list-row__meta-icon {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    /* mittig zur ersten Textzeile (18 px) */
    margin-top: 1px;
}

.list-row__note {
    overflow: hidden;
    color: var(--color-text-2);
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Status ohne Notiz darf umbrechen, damit er ganz lesbar bleibt */
.list-row__status-text {
    min-width: 0;
    overflow-wrap: anywhere;
}

.list-row__quantity {
    flex: none;
}

.list-row--done .list-row__quantity {
    color: var(--color-text-2);
}

/* 3 · Abhaken */
.list-row__check-zone {
    position: relative;
    display: flex;
    flex: none;
    width: var(--check-zone-width);
}

/* Trennlinie, kürzer als die Zeile */
.list-row__check-zone::before {
    content: "";
    position: absolute;
    top: var(--space-3);
    bottom: var(--space-3);
    left: 0;
    width: 1px;
    background: var(--color-border-subtle);
}

.list-row__check {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.list-row__ring {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--check-ring-size);
    height: var(--check-ring-size);
    border: 2px solid var(--color-ring);
    border-radius: 50%;
    transition:
        background-color var(--duration-fast) var(--ease-out),
        border-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.list-row__check:active .list-row__ring,
.list-row__check:active .list-row__transfer {
    transform: scale(0.9);
}

.list-row--done .list-row__ring {
    border-color: var(--color-done);
    background: var(--color-done);
    color: var(--color-surface-1);
}

.list-row__ring-icon {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    stroke-width: 3;
}

.list-row__transfer {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--check-ring-size);
    height: var(--check-ring-size);
    border: 2px solid var(--color-notgot);
    border-radius: 50%;
    color: var(--color-notgot);
    transition: transform var(--duration-fast) var(--ease-out);
}

.list-row__transfer-icon {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    stroke-width: 2.5;
}

.list-row__handle:focus-visible,
.list-row__details:focus-visible,
.list-row__check:focus-visible {
    outline-offset: calc(-1 * var(--focus-ring-width));
}

@media (hover: hover) {
    .list-row__details:hover .list-row__name {
        text-decoration: underline;
        text-decoration-color: var(--color-border-strong);
        text-underline-offset: 0.15em;
    }

    .list-row--done .list-row__details:hover .list-row__name {
        text-decoration: line-through;
    }

    .list-row__check:hover .list-row__ring {
        border-color: var(--color-done);
    }
}
</style>
