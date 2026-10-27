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
 * Sortieren selbst (Ziehen, Platzhalter) kommt mit der Einkaufsliste; der
 * Griff meldet nur `handle-pointerdown`.
 */
import { computed } from 'vue';
import { ArrowRight, Check, CloudUpload, GripVertical, X } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import QuantityLabel from './QuantityLabel.vue';
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
    }
});

const emit = defineEmits(['details', 'toggle', 'transfer', 'handle-pointerdown']);

const isDone = computed(() => props.status === 'done');
const isNotGot = computed(() => props.status === 'notGot');
</script>

<template>
    <li
        class="list-row"
        :class="{
            'list-row--done': isDone,
            'list-row--not-got': isNotGot
        }"
    >
        <div class="list-row__handle-zone">
            <button
                v-if="status === 'open'"
                type="button"
                class="list-row__handle"
                :aria-label="t('row.handle', { name })"
                @pointerdown="emit('handle-pointerdown', $event)"
            >
                <GripVertical class="list-row__handle-icon" aria-hidden="true" />
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
                    {{ t('row.notGot') }}
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
            <button
                v-if="isNotGot"
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
    </li>
</template>

<style scoped>
.list-row {
    display: flex;
    align-items: stretch;
    min-height: var(--row-min-height);
    background: var(--color-surface-1);
}

.list-row + .list-row {
    border-top: 1px solid var(--color-border-subtle);
}

.list-row--done {
    background: var(--color-surface-done);
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
