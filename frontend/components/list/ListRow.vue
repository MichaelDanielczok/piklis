<script setup>
/**
 * Zeile einer Einkaufsliste mit drei getrennten Bedienflächen:
 *
 * 1. Ziehgriff (nur offen): Sortieren nur hier. 32 px breit über die ganze
 *    Zeilenhöhe (Designpaket 28 × 48; breiter und höher, weil es so am Handy
 *    sicherer zu treffen ist und auf dem 4-px-Raster liegt).
 * 2. Details: Bild, Name (max. 2 Zeilen), Notiz bzw. Status, Menge rechts.
 *    Mit `packageSize` steht die Packungsgröße klein und grau hinter dem
 *    Namen; der Name bleibt dann einzeilig und wird gekürzt, damit die Zeile
 *    nicht höher wird und die Größe sichtbar bleibt. Tippen öffnet das
 *    Produktdetail (`details`).
 * 3. Abhaken-Zone 60 px breit über die ganze Zeilenhöhe hinter einer
 *    Trennlinie, Ring 28 px (`toggle`). Bei „nicht bekommen“ steht dort der
 *    Knopf zum Übernehmen in eine andere Liste (`transfer`).
 *
 * Zustände (status): open · done (Haken gefüllt, durchgestrichen, kein Griff) ·
 * notGot (Kreuz und Text „nicht bekommen“). Unabhängig davon `unsynced`:
 * eigene Zeile mit Wolke und Text „noch nicht übertragen“ (auch bei
 * erledigten und nicht bekommenen Einträgen, z. B. offline abgehakt), die
 * Notiz darunter.
 * Status nie nur über Farbe: immer Symbol und Text dazu.
 *
 * Sortieren selbst (Ziehen, Platzhalter) übernimmt die Liste
 * (`useDragSort`): Der Griff meldet `handle-pointerdown`, mit der Tastatur
 * (Pfeil hoch/runter am Griff) `move` mit -1 bzw. 1.
 *
 * Wischen nach links (`swipe`: Liste aus `{ action, label, variant }`,
 * `useRowSwipe`) legt Knöpfe frei, z. B. „Nicht bekommen“ und „Entfernen“;
 * Antippen meldet `swipe-action` mit `action`, das Wischen selbst löst nichts
 * aus. Dieselben Aktionen gibt es im Produkt im Listenkontext.
 * `transferredTo`: Name der Liste, in die ein nicht bekommenes Produkt
 * übernommen wurde. `readonly` (abgeschlossene Liste): Zustand nur als
 * Symbol, ohne Griff und ohne Abhaken.
 *
 * `quantityControl`: Halten auf der Zeile öffnet das Mengen-Control
 * (`useQuantityHold`, nicht auf Griff und Abhaken). Es meldet `quantity` mit der
 * neuen Menge, `details` und `remove`. Tastatur und Bildschirmleser erreichen es
 * über den Knopf „Menge“, der erst mit dem Fokus erscheint.
 *
 * Vorlagen (`trailing`): `remove` zeigt rechts statt des Abhakens einen
 * Knopf zum Herausnehmen (`remove`), `none` lässt die Fläche weg (Vorlage
 * ansehen).
 */
import { computed, useId, useTemplateRef } from 'vue';
import { ArrowRight, Check, CloudUpload, GripVertical, RotateCcw, Trash2, X } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import QuantityControl from './QuantityControl.vue';
import QuantityLabel from './QuantityLabel.vue';
import { useQuantityHold } from '@/composables/useQuantityHold.js';
import { useRowSwipe } from '@/composables/useRowSwipe.js';
import { t } from '@/i18n/index.js';

// Symbol je Wisch-Aktion, Status nie nur über Farbe
const SWIPE_ICONS = { neutral: X, danger: Trash2, accent: RotateCcw };

const props = defineProps({
    name: {
        type: String,
        required: true
    },
    packageSize: {
        type: String,
        default: null
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
    category: {
        type: String,
        default: null
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
        type: Array,
        default: null
    },
    quantityControl: {
        type: Boolean,
        default: false
    },
    trailing: {
        type: String,
        default: 'check',
        validator: (value) => ['check', 'remove', 'none'].includes(value)
    }
});

const emit = defineEmits(['details', 'toggle', 'transfer', 'handle-pointerdown', 'move', 'swipe-action', 'remove', 'quantity']);

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
const actionsRef = useTemplateRef('actions');
const { offset, dragging, close, release, listeners, onClickCapture } = useRowSwipe({
    root: rootRef,
    actions: actionsRef,
    enabled: () => props.swipe !== null && !props.readonly
});

const hold = useQuantityHold({
    root: useTemplateRef('content'),
    enabled: () => props.quantityControl && !props.readonly && offset.value === 0,
    amount: () => props.amount,
    unit: () => props.unit,
    onCommit: (amount) => emit('quantity', amount),
    onHold: release
});
const { control } = hold;

/** Geschlossene Zeile: zweites Tippen beim Zuschieben löst nicht noch einmal aus. */
function runSwipeAction(action) {
    if (offset.value === 0) {
        return;
    }

    close();
    emit('swipe-action', action);
}
</script>

<template>
    <li
        ref="root"
        class="list-row"
        :class="{
            'list-row--done': isDone,
            'list-row--not-got': isNotGot,
            'list-row--readonly': readonly,
            'list-row--no-trailing': trailing === 'none',
            'list-row--swiped': offset !== 0,
            'list-row--held': control
        }"
        v-on="listeners"
        @click.capture="onClickCapture"
    >
        <!-- Kurzweg für den Finger; Tastatur und Bildschirmleser nutzen das Produkt im Listenkontext.
             mousedown.prevent: Mausklick gibt keinen Fokus in den aria-hidden-Bereich -->
        <div v-if="swipe && !readonly" class="list-row__swipe-zone" aria-hidden="true">
            <div ref="actions" class="list-row__swipe-actions">
                <button
                    v-for="item in swipe"
                    :key="item.action"
                    type="button"
                    tabindex="-1"
                    class="list-row__swipe-action"
                    :class="'list-row__swipe-action--' + item.variant"
                    data-name="swipe-action"
                    @mousedown.prevent
                    @click="runSwipeAction(item.action)"
                >
                    <component :is="SWIPE_ICONS[item.variant]" class="list-row__swipe-icon" />
                    <span>{{ item.label }}</span>
                </button>
            </div>
        </div>

        <div
            ref="content"
            class="list-row__content"
            :class="{ 'is-dragging': dragging }"
            :style="offset !== 0 ? { transform: `translateX(${offset}px)` } : undefined"
            v-on="hold.listeners"
            @click.capture="hold.onClickCapture"
        >
            <div class="list-row__handle-zone" data-no-hold>
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
                <ProductImage :image-url="imageUrl" :icon="icon" :category="category" :muted="isDone" />

                <span class="list-row__text">
                    <span class="list-row__title">
                        <span class="list-row__name" :class="{ 'list-row__name--single': packageSize }">{{ name }}</span>
                        <span v-if="packageSize" class="list-row__size">
                            <span class="visually-hidden">{{ t('products.packageSize') }}:</span>
                            {{ packageSize }}
                        </span>
                    </span>

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
                    <span v-if="unsynced" class="list-row__meta list-row__meta--unsynced">
                        <CloudUpload class="list-row__meta-icon" aria-hidden="true" />
                        <span class="list-row__status-text">{{ t('row.unsynced') }}</span>
                    </span>
                    <span v-if="note && !isDone && !isNotGot" class="list-row__meta">
                        <span class="list-row__note">{{ note }}</span>
                    </span>
                </span>

                <QuantityLabel class="list-row__quantity" :amount="amount" :unit="unit" />
            </button>

            <button
                v-if="quantityControl && !readonly"
                type="button"
                class="list-row__more visually-hidden-focusable"
                aria-haspopup="dialog"
                :aria-label="t('quantityControl.open', { name })"
                @click="hold.openMenu($event.currentTarget)"
            >
                {{ t('quantityControl.openShort') }}
            </button>

            <div v-if="trailing === 'remove'" class="list-row__check-zone" data-no-hold>
                <button
                    type="button"
                    class="list-row__check list-row__check--remove"
                    data-name="remove"
                    :aria-label="t('row.remove', { name })"
                    @click="emit('remove')"
                >
                    <Trash2 class="list-row__remove-icon" aria-hidden="true" />
                </button>
            </div>
            <div v-else-if="trailing === 'check'" class="list-row__check-zone" data-no-hold>
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

        <QuantityControl
            v-if="control"
            :state="control"
            :name="name"
            :amount="amount"
            :unit="unit"
            @change="emit('quantity', $event)"
            @details="emit('details')"
            @remove="emit('remove')"
            @close="hold.close"
        />
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
    /* Halten darf weder Text markieren noch das iOS-Menü für Bilder öffnen */
    user-select: none;
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    transition: transform var(--duration-medium) var(--ease-out);
}

.list-row__content :deep(img) {
    pointer-events: none;
    -webkit-user-drag: none;
}

.list-row--held .list-row__content {
    outline: 2px solid var(--color-accent);
    outline-offset: -2px;
}

.list-row__content.is-dragging {
    transition: none;
}

.list-row--done,
.list-row--done .list-row__content {
    background: var(--color-surface-done);
}

/* Wisch-Aktionen hinter der Zeile, rechts; bleiben stehen, bis die Zeile zu ist */
.list-row__swipe-zone {
    position: absolute;
    inset: 0;
    display: flex;
    justify-content: flex-end;
    visibility: hidden;
    transition: visibility 0s linear var(--duration-medium);
}

.list-row--swiped .list-row__swipe-zone {
    visibility: visible;
    transition: none;
}

.list-row__swipe-actions {
    display: flex;
    flex: none;
}

.list-row__swipe-action {
    display: flex;
    flex: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-1);
    width: var(--swipe-action-width);
    padding: 0 var(--space-2);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-note);
    text-align: center;
}

.list-row__swipe-action--neutral {
    background: var(--color-swipe-neutral);
    color: var(--color-on-swipe-neutral);
}

.list-row__swipe-action--danger {
    background: var(--color-danger);
    color: var(--color-on-danger);
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
    .list-row__content,
    .list-row__swipe-zone {
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

.list-row__title {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    min-width: 0;
}

.list-row__name {
    display: -webkit-box;
    min-width: 0;
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

.list-row__name--single {
    display: block;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.list-row--done .list-row__name {
    color: var(--color-text-2);
    text-decoration: line-through;
}

/* flex: none, damit zuerst der Name gekürzt wird; sehr lange Angaben kürzt max-width */
.list-row__size {
    flex: none;
    max-width: 40%;
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
    white-space: nowrap;
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

/* ohne Fläche rechts (Vorlage ansehen): Menge mit Abstand zum Rand */
.list-row--no-trailing .list-row__details {
    padding-right: var(--space-4);
}

/* erst mit dem Fokus sichtbar: am Finger öffnet Halten dasselbe Control */
.list-row__more:focus-visible {
    flex: none;
    align-self: center;
    margin-right: var(--space-2);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    outline: var(--focus-ring-width) solid var(--color-accent);
    font-size: var(--font-size-small);
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

.list-row__check--remove {
    color: var(--color-text-2);
}

.list-row__remove-icon {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    transition: transform var(--duration-fast) var(--ease-out);
}

.list-row__check--remove:active .list-row__remove-icon {
    transform: scale(0.9);
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

    .list-row__check--remove:hover {
        color: var(--color-danger);
    }
}
</style>
