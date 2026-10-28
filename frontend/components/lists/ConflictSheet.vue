<script setup>
/**
 * Konfliktauswahl (docs/architecture.md, „Conflict rules“): Haben zwei
 * Personen denselben Eintrag gleichzeitig verschieden geändert, fragt die App
 * statt still zu überschreiben. Ein Sheet für alle Fälle, ältester Konflikt
 * zuerst, „1 von 2“ bei mehreren:
 *
 * - Menge oder Notiz: „Deine Änderung“ · „Andere Änderung“
 * - alter Haken („2 abgehakt, inzwischen 3 benötigt“): Erledigt · Offen lassen
 * - inzwischen entfernt: Wiederherstellen · Entfernt lassen
 *
 * Gestaltung wie die anderen Auswahl-Sheets (Auswahlkarten aus forms.css,
 * Produkt mit Bild oben). Schließen ohne Auswahl lässt die Liste, wie sie
 * gerade ist (der Stand des Servers steht schon da).
 *
 * Liegt in App.vue, damit Konflikte auf jeder Seite ankommen.
 */
import { computed, ref, useId, watch } from 'vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import AppButton from '@/components/ui/AppButton.vue';
import ProductImage from '@/components/products/ProductImage.vue';
import { dismissConflict, resolveConflict, useLists } from '@/composables/useLists.js';
import { useProducts } from '@/composables/useProducts.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatAmount, formatQuantity } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

const { conflicts, activeLists, archivedLists } = useLists();
const { findProduct } = useProducts();

const groupName = useId();
const open = ref(false);
const choice = ref(null);
let answering = false;

const current = computed(() => conflicts.value[0] ?? null);

const product = computed(() => (current.value ? findProduct(current.value.productId) : null));
const productName = computed(() => product.value?.name ?? t('entry.unknownProduct'));

const listName = computed(() => {
    const listId = current.value?.listId;

    return [...activeLists.value, ...archivedLists.value].find((list) => list.id === listId)?.name ?? '';
});

const title = computed(() => {
    switch (current.value?.conflict.type) {
        case 'note':
            return t('conflict.titleNote');
        case 'checkedLess':
            return t('conflict.titleCheckedLess');
        case 'removed':
            return t('conflict.titleRemoved');
        default:
            return t('conflict.titleQuantity');
    }
});

const subtitle = computed(() => {
    const parts = [listName.value];

    if (conflicts.value.length > 1) {
        parts.push(t('conflict.position', { index: 1, count: conflicts.value.length }));
    }

    return parts.filter(Boolean).join(' · ');
});

function quantityText(quantity) {
    return quantity ? formatQuantity(quantity.amount, quantity.unit) : '';
}

/** Im Satz „2 abgehakt, inzwischen 3 benötigt“ ohne Einheit nur die Zahl. */
function sentenceQuantity(quantity) {
    return quantity?.unit ? quantityText(quantity) : formatAmount(quantity?.amount ?? 0);
}

function noteText(note) {
    return note ? note : t('conflict.noNote');
}

const text = computed(() => {
    const conflict = current.value?.conflict;

    switch (conflict?.type) {
        case 'note':
            return t('conflict.textNote');
        case 'checkedLess':
            return t('conflict.textCheckedLess', {
                checked: sentenceQuantity(conflict.checked),
                current: sentenceQuantity(conflict.current)
            });
        case 'removed':
            return t('conflict.textRemoved', { name: productName.value });
        default:
            return t('conflict.textQuantity');
    }
});

/** Möglichkeiten je Art: { value, title, hint } */
const options = computed(() => {
    const conflict = current.value?.conflict;

    switch (conflict?.type) {
        case 'quantity':
            return [
                { value: 'yours', title: quantityText(conflict.yours), hint: t('conflict.yours') },
                { value: 'theirs', title: quantityText(conflict.theirs), hint: t('conflict.theirs') }
            ];
        case 'note':
            return [
                { value: 'yours', title: noteText(conflict.yours), hint: t('conflict.yours') },
                { value: 'theirs', title: noteText(conflict.theirs), hint: t('conflict.theirs') }
            ];
        case 'checkedLess':
            return [
                { value: 'done', title: t('conflict.done'), hint: t('conflict.doneHint', { quantity: quantityText(conflict.current) }) },
                { value: 'open', title: t('conflict.open'), hint: t('conflict.openHint') }
            ];
        case 'removed':
            return [
                { value: 'restore', title: t('conflict.restore'), hint: t('conflict.restoreHint') },
                { value: 'keep', title: t('conflict.keep'), hint: t('conflict.keepHint') }
            ];
        default:
            return [];
    }
});

// neuer Konflikt: Sheet öffnen, Auswahl leeren; keiner mehr: schließen
watch(current, (item, previous) => {
    if (item?.key !== previous?.key) {
        choice.value = null;
    }

    open.value = item !== null;
}, { immediate: true });

function onOpenChange(value) {
    if (value) {
        return;
    }

    open.value = false;

    // ohne Antwort geschlossen (Wischen, Daneben-Tippen, Escape, X): Stand bleibt
    if (!answering && current.value) {
        dismissConflict(current.value);
    }
}

async function submit() {
    if (!current.value || choice.value === null) {
        return;
    }

    answering = true;

    try {
        await resolveConflict(current.value, choice.value);
    } finally {
        answering = false;
    }
}
</script>

<template>
    <AppSheet :open="open" :title="title" :subtitle="subtitle" @update:open="onOpenChange">
        <div v-if="current" class="conflict">
            <div class="conflict__product">
                <ProductImage
                    :image-url="product?.imageUrl ?? null"
                    :icon="product ? iconOf(product) : undefined"
                    :category="categoryOf(product)"
                />
                <p class="conflict__name">{{ productName }}</p>
            </div>

            <p class="conflict__text">{{ text }}</p>

            <fieldset class="choice-list">
                <legend class="visually-hidden">{{ t('conflict.choose') }}</legend>
                <label v-for="option in options" :key="option.value" class="choice">
                    <input v-model="choice" class="choice__radio" type="radio" :name="groupName" :value="option.value">
                    <span class="choice__text">
                        <span class="choice__title">{{ option.title }}</span>
                        <span class="choice__hint">{{ option.hint }}</span>
                    </span>
                </label>
            </fieldset>

            <p class="conflict__hint">{{ t('conflict.closeHint') }}</p>
        </div>

        <template #footer>
            <AppButton block :disabled="choice === null" @click="submit">{{ t('conflict.submit') }}</AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.conflict {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.conflict__product {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

.conflict__name {
    min-width: 0;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
}

.conflict__text {
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.conflict__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
