<script setup>
/**
 * Karte „In der Liste …“ im Produkt im Listenkontext (Designpaket 10):
 * Menge, Einkaufsnotiz und Zustand dieses Einkaufs, darunter die Aktionen.
 *
 * - offen: „Menge oder Notiz ändern“ und „Nicht bekommen“ (dieselbe Aktion
 *   wie Wischen in der Liste, hier auch für Tastatur und Bildschirmleser);
 * - nicht bekommen: „In andere Liste übernehmen“ und „Wieder offen“;
 * - abgeschlossene Liste (`readonly`): nur lesen, mit Hinweis.
 *
 * Emits: edit · not-got · reopen · transfer
 */
import { Check, Pencil, RotateCcw, ArrowRight, X } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import QuantityLabel from '@/components/list/QuantityLabel.vue';
import { t } from '@/i18n/index.js';

defineProps({
    entry: {
        type: Object,
        required: true
    },
    listName: {
        type: String,
        default: ''
    },
    transferredTo: {
        type: String,
        default: null
    },
    readonly: {
        type: Boolean,
        default: false
    }
});

defineEmits(['edit', 'not-got', 'reopen', 'transfer']);
</script>

<template>
    <section class="entry-card" :aria-label="t('entry.context', { name: listName })">
        <h3 class="entry-card__label">{{ t('entry.context', { name: listName }) }}</h3>
        <dl class="entry-card__facts">
            <div class="entry-card__fact">
                <dt>{{ t('entry.quantity') }}</dt>
                <dd><QuantityLabel :amount="entry.quantity.amount" :unit="entry.quantity.unit" /></dd>
            </div>
            <div class="entry-card__fact">
                <dt>{{ t('entry.note') }}</dt>
                <dd :class="{ 'entry-card__empty': !entry.note }">{{ entry.note || t('entry.noNote') }}</dd>
            </div>
            <div v-if="entry.status === 'done'" class="entry-card__fact">
                <dt class="visually-hidden">{{ t('entry.status') }}</dt>
                <dd class="entry-card__status entry-card__status--done">
                    <Check aria-hidden="true" />
                    {{ t('entry.statusDone') }}
                </dd>
            </div>
            <div v-else-if="entry.status === 'notGot'" class="entry-card__fact">
                <dt class="visually-hidden">{{ t('entry.status') }}</dt>
                <dd class="entry-card__status entry-card__status--not-got">
                    <X aria-hidden="true" />
                    {{ transferredTo === null
                        ? t('entry.statusNotGot')
                        : (transferredTo ? t('entry.statusTransferred', { list: transferredTo }) : t('row.notGotTransferredElsewhere')) }}
                </dd>
            </div>
        </dl>

        <p v-if="readonly" class="entry-card__readonly">{{ t('entry.readonly') }}</p>

        <div v-else class="entry-card__actions">
            <AppButton variant="secondary" block @click="$emit('edit')">
                <Pencil aria-hidden="true" />
                {{ t('entry.edit') }}
            </AppButton>
            <AppButton v-if="entry.status === 'open'" variant="secondary" block @click="$emit('not-got')">
                <X aria-hidden="true" />
                {{ t('entry.notGot') }}
            </AppButton>
            <template v-else-if="entry.status === 'notGot'">
                <AppButton variant="secondary" block aria-haspopup="dialog" @click="$emit('transfer')">
                    <ArrowRight aria-hidden="true" />
                    {{ t('entry.transfer') }}
                </AppButton>
                <AppButton variant="secondary" block @click="$emit('reopen')">
                    <RotateCcw aria-hidden="true" />
                    {{ t('entry.reopen') }}
                </AppButton>
            </template>
        </div>
    </section>
</template>

<style scoped>
.entry-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.entry-card__label {
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}

.entry-card__facts {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
}

.entry-card__fact {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-4);
}

.entry-card__fact dt {
    flex: none;
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.entry-card__fact dd {
    min-width: 0;
    margin: 0;
    font-size: var(--font-size-name);
    line-height: var(--line-height-name);
    text-align: right;
    overflow-wrap: anywhere;
}

.entry-card__empty {
    color: var(--color-text-2);
}

.entry-card__status {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    font-weight: var(--font-weight-semibold);
}

.entry-card__status--done {
    color: var(--color-done);
}

.entry-card__status--not-got {
    color: var(--color-notgot);
}

.entry-card__status :deep(svg) {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    stroke-width: 3;
}

.entry-card__actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.entry-card__readonly {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}
</style>
