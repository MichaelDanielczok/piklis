<script setup>
/**
 * Auswahl des Ersatzicons als Sheet: durchsuchbar (Icon- oder
 * Kategoriename), nach Kategorien gegliedert. Das gewählte Icon trägt
 * `aria-pressed` und eine grüne Kontur; Antippen übernimmt und schließt.
 *
 * v-model:open · v-model (Icon-Schlüssel, `allgemein` = Standard)
 */
import { computed, ref, watch } from 'vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import SearchField from '@/components/ui/SearchField.vue';
import ProductIcon from './ProductIcon.vue';
import { CATEGORIES, DEFAULT_ICON, ICONS } from '@/products/catalog.js';
import { normalizeSearchText, searchTokens } from '@/utils/productSearch.js';
import { t } from '@/i18n/index.js';

const open = defineModel('open', { type: Boolean, default: false });
const icon = defineModel({ type: String, default: DEFAULT_ICON });

const query = ref('');

watch(open, (value) => {
    if (value) {
        query.value = '';
    }
});

const groups = computed(() => {
    const tokens = searchTokens(query.value);

    return CATEGORIES
        .map((category) => {
            const categoryName = t('categories.' + category);
            const icons = ICONS
                .filter((item) => item.category === category)
                .map((item) => ({ ...item, name: t('icons.' + item.key) }))
                .filter((item) => {
                    const text = normalizeSearchText(item.name + ' ' + categoryName + ' ' + item.key);

                    return tokens.every((token) => text.includes(token));
                });

            return { category, name: categoryName, icons };
        })
        .filter((group) => group.icons.length > 0);
});

function choose(key) {
    icon.value = key;
    open.value = false;
}
</script>

<template>
    <AppSheet v-model:open="open" :title="t('iconPicker.title')">
        <div class="icon-picker">
            <SearchField
                v-model="query"
                :label="t('iconPicker.search')"
                :placeholder="t('iconPicker.searchPlaceholder')"
            />

            <p v-if="groups.length === 0" class="icon-picker__empty" role="status">
                {{ t('iconPicker.noResults', { query }) }}
            </p>

            <section v-for="group in groups" :key="group.category" class="icon-picker__group">
                <h3 class="icon-picker__label">{{ group.name }}</h3>
                <ul class="icon-picker__grid">
                    <li v-for="item in group.icons" :key="item.key">
                        <button
                            type="button"
                            class="icon-picker__option"
                            :aria-pressed="item.key === icon ? 'true' : 'false'"
                            @click="choose(item.key)"
                        >
                            <span class="icon-picker__field">
                                <ProductIcon class="icon-picker__icon" :name="item.key" />
                            </span>
                            <span class="icon-picker__name">{{ item.name }}</span>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </AppSheet>
</template>

<style scoped>
.icon-picker {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    /* feste Mindesthöhe: das Sheet springt beim Suchen nicht zusammen */
    min-height: var(--icon-picker-min-height);
}

.icon-picker__empty {
    padding: var(--space-4) 0;
    color: var(--color-text-2);
}

.icon-picker__label {
    padding: var(--space-3) 0 var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}

.icon-picker__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--icon-option-size), 1fr));
    gap: var(--space-2);
}

.icon-picker__option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-1);
    width: 100%;
    padding: var(--space-2) var(--space-1);
    border-radius: var(--radius-md);
    color: var(--color-text-1);
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .icon-picker__option:hover {
        background: var(--color-surface-2);
    }
}

.icon-picker__field {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--tap-size-main);
    height: var(--tap-size-main);
    border: 2px solid transparent;
    border-radius: var(--radius-sm);
    background: var(--color-surface-2);
    color: var(--color-icon);
}

.icon-picker__option[aria-pressed="true"] .icon-picker__field {
    border-color: var(--color-accent);
    background: var(--color-accent-soft);
}

.icon-picker__option[aria-pressed="true"] .icon-picker__name {
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
}

.icon-picker__icon {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.icon-picker__name {
    max-width: 100%;
    overflow: hidden;
    font-size: var(--font-size-caption);
    line-height: var(--line-height-label);
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
