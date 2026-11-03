<script setup>
/**
 * Zutaten und Nährwerte im Produktdetail, je aufklappbar (`<details>`),
 * dazu Quelle und Lizenz von Open Food Facts (Obsidian „Piklis Konzept“ ›
 * „Produktansicht“ und „Open Food Facts“).
 *
 * Nährwerte als Tabelle mit ihrem Bezug: Spalte „je 100 g“ bzw. „je
 * 100 ml“, mit Portionswerten eine zweite Spalte „je Portion (…)“. Energie
 * steht in einer Zeile („267 kJ / 64 kcal“). Fehlt ein Wert, steht „–“.
 *
 * Quelle erscheint, sobald Daten oder ein Bild von Open Food Facts stammen:
 * Abrufdatum, Link zum Produkt, ODbL für die Daten, CC BY-SA für das Bild.
 * Wer das Produkt bearbeiten darf, kann neu abrufen oder die Verknüpfung
 * lösen (übernommene Namen, Marke, Größe und Bild bleiben).
 *
 * Props: product, canEdit · Emits: refresh, unlink
 */
import { computed, useId } from 'vue';
import { ChevronDown, ExternalLink } from '@lucide/vue';
import { hasServingColumn, nutrientFormat } from '@/utils/foodFacts.js';
import { formatDate, formatNumber, t } from '@/i18n/index.js';

const ODBL_URL = 'https://opendatacommons.org/licenses/odbl/1-0/';
const CC_BY_SA_URL = 'https://creativecommons.org/licenses/by-sa/3.0/deed.de';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    canEdit: {
        type: Boolean,
        default: false
    },
    pending: {
        type: Boolean,
        default: false
    }
});

defineEmits(['refresh', 'unlink']);

const captionId = useId();

const facts = computed(() => props.product.foodFacts ?? null);
const nutrition = computed(() => facts.value?.nutrition ?? null);
const withServing = computed(() => hasServingColumn(nutrition.value));
const imageFromOff = computed(() => props.product.imageSource === 'openfoodfacts' && Boolean(props.product.image));
const showSource = computed(() => Boolean(facts.value) || imageFromOff.value);

function format(value, unit) {
    if (value === null || value === undefined) {
        return null;
    }

    return formatNumber(value, nutrientFormat(value, unit)) + ' ' + unit;
}

/** Zeilen der Tabelle; kJ und kcal zusammen als „Energie“. */
const rows = computed(() => {
    const items = nutrition.value?.rows ?? [];
    const energy = items.filter((row) => row.key === 'energy-kj' || row.key === 'energy-kcal');
    const joined = (column) => {
        const parts = energy.map((row) => format(row[column], row.unit)).filter(Boolean);

        return parts.length > 0 ? parts.join(' / ') : null;
    };
    const result = [];

    if (energy.length > 0) {
        result.push({ key: 'energy', per100: joined('per100'), perServing: joined('perServing'), sub: false });
    }

    for (const row of items) {
        if (row.key === 'energy-kj' || row.key === 'energy-kcal') {
            continue;
        }

        result.push({
            key: row.key,
            per100: format(row.per100, row.unit),
            perServing: format(row.perServing, row.unit),
            sub: row.key === 'saturated-fat' || row.key === 'sugars'
        });
    }

    return result;
});
</script>

<template>
    <div v-if="facts?.ingredients || nutrition || showSource" class="facts">
        <details v-if="facts?.ingredients" class="facts__section">
            <summary class="facts__summary">
                <span>{{ t('foodFacts.ingredients') }}</span>
                <ChevronDown class="facts__chevron" aria-hidden="true" />
            </summary>
            <p class="facts__ingredients" :lang="facts.language || undefined">{{ facts.ingredients }}</p>
        </details>

        <details v-if="nutrition" class="facts__section">
            <summary class="facts__summary">
                <span>{{ t('foodFacts.nutrition') }}</span>
                <ChevronDown class="facts__chevron" aria-hidden="true" />
            </summary>
            <table class="facts__table" :aria-describedby="captionId">
                <caption :id="captionId" class="visually-hidden">
                    {{ t('foodFacts.nutritionCaption', { name: product.name }) }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="facts__head-name">{{ t('foodFacts.nutrient') }}</th>
                        <th scope="col">{{ t('foodFacts.per100', { per: nutrition.per }) }}</th>
                        <th v-if="withServing" scope="col">
                            {{ t('foodFacts.perServing') }}
                            <span v-if="nutrition.servingSize" class="facts__serving">({{ nutrition.servingSize }})</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key">
                        <th scope="row" class="facts__name" :class="{ 'facts__name--sub': row.sub }">
                            {{ t('foodFacts.nutrients.' + row.key) }}
                        </th>
                        <td>
                            <template v-if="row.per100">{{ row.per100 }}</template>
                            <template v-else>
                                <span aria-hidden="true">–</span>
                                <span class="visually-hidden">{{ t('foodFacts.noValue') }}</span>
                            </template>
                        </td>
                        <td v-if="withServing">
                            <template v-if="row.perServing">{{ row.perServing }}</template>
                            <template v-else>
                                <span aria-hidden="true">–</span>
                                <span class="visually-hidden">{{ t('foodFacts.noValue') }}</span>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>

        <div v-if="showSource" class="facts__source">
            <p>
                <template v-if="facts">{{ t('foodFacts.source', { date: formatDate(facts.fetchedAt) }) }}</template>
                <template v-else>{{ t('foodFacts.sourceImageOnly') }}</template>
                {{ t('foodFacts.disclaimer') }}
            </p>
            <p class="facts__links">
                <a
                    v-if="facts?.url"
                    :href="facts.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="facts__link"
                >
                    {{ t('foodFacts.productPage') }}
                    <ExternalLink class="facts__link-icon" aria-hidden="true" />
                    <span class="visually-hidden">{{ t('foodFacts.newWindow') }}</span>
                </a>
                <a v-if="facts" :href="ODBL_URL" target="_blank" rel="noopener noreferrer" class="facts__link">
                    {{ t('foodFacts.licenceData') }}
                    <span class="visually-hidden">{{ t('foodFacts.newWindow') }}</span>
                </a>
                <a v-if="imageFromOff" :href="CC_BY_SA_URL" target="_blank" rel="noopener noreferrer" class="facts__link">
                    {{ t('foodFacts.licenceImage') }}
                    <span class="visually-hidden">{{ t('foodFacts.newWindow') }}</span>
                </a>
            </p>
            <p v-if="canEdit && facts" class="facts__actions">
                <button type="button" class="facts__action" :disabled="pending" @click="$emit('refresh')">
                    {{ t('foodFacts.refresh') }}
                </button>
                <button type="button" class="facts__action" :disabled="pending" @click="$emit('unlink')">
                    {{ t('foodFacts.unlink') }}
                </button>
            </p>
        </div>
    </div>
</template>

<style scoped>
.facts {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.facts__section {
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.facts__summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    min-height: var(--tap-size-main);
    padding: 0 var(--space-4);
    border-radius: var(--radius-md);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    list-style: none;
    cursor: pointer;
}

.facts__summary::-webkit-details-marker {
    display: none;
}

.facts__summary:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 2px;
}

.facts__chevron {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-text-2);
    transition: transform var(--duration-fast) var(--ease-out);
}

.facts__section[open] .facts__chevron {
    transform: rotate(180deg);
}

.facts__ingredients {
    padding: 0 var(--space-4) var(--space-4);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
    overflow-wrap: anywhere;
}

.facts__table {
    width: calc(100% - 2 * var(--space-4));
    margin: 0 var(--space-4) var(--space-3);
    border-collapse: collapse;
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    line-height: var(--line-height-note);
}

.facts__table th,
.facts__table td {
    padding: var(--space-2) 0;
    border-top: 1px solid var(--color-border-subtle);
    text-align: right;
    vertical-align: top;
}

.facts__table th + th,
.facts__table td {
    padding-left: var(--space-3);
}

.facts__table thead th {
    border-top: 0;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
}

.facts__table .facts__head-name,
.facts__table .facts__name {
    text-align: left;
}

.facts__name {
    font-weight: var(--font-weight-medium);
}

.facts__table .facts__name--sub {
    padding-left: var(--space-3);
    color: var(--color-text-2);
    font-weight: var(--font-weight-regular);
}

.facts__serving {
    display: block;
    font-weight: var(--font-weight-regular);
}

.facts__source {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.facts__links,
.facts__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1) var(--space-4);
}

.facts__link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    min-height: var(--tap-size);
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
    text-decoration: underline;
    text-underline-offset: 0.15em;
}

.facts__link-icon {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.facts__action {
    min-height: var(--tap-size);
    color: var(--color-accent-text);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
}

.facts__action:disabled {
    opacity: 0.6;
}

.facts__link:focus-visible,
.facts__action:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 2px;
    border-radius: var(--radius-xs);
}
</style>
