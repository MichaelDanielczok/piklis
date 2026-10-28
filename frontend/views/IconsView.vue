<script setup>
/**
 * Übersicht aller Ersatzicons (nur im Entwicklungsserver registriert, siehe
 * router.js): nach Kategorie gruppiert, jedes Icon im 44-px-Feld (Icon
 * 30 px) in seiner Kategorienfarbe, mit Name und Quelle (Lucide,
 * Designpaket, eigen). Dient dazu, Ausreißer im Stil zu finden und die
 * Farben hell und dunkel zu prüfen.
 */
import { computed } from 'vue';
import AppPage from '@/components/layout/AppPage.vue';
import ProductImage from '@/components/products/ProductImage.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import { ICON_LIST } from '@/icons/sprite.js';
import { CATEGORIES, colorOf } from '@/products/catalog.js';
import { t } from '@/i18n/index.js';

const SOURCE_KEYS = { lucide: 'iconsOverview.sourceLucide', design: 'iconsOverview.sourceDesign', own: 'iconsOverview.sourceOwn' };

function kindOf(source) {
    return source.split(':')[0];
}

const counts = computed(() => {
    const result = { lucide: 0, design: 0, own: 0 };

    for (const { source } of ICON_LIST) {
        result[kindOf(source)] += 1;
    }

    return result;
});

const groups = computed(() => CATEGORIES.map((category) => ({
    category,
    family: colorOf(category),
    icons: ICON_LIST
        .filter((icon) => icon.category === category)
        .map((icon) => ({ ...icon, kind: kindOf(icon.source) }))
})));
</script>

<template>
    <AppPage :title="t('iconsOverview.title')" :subtitle="t('iconsOverview.intro')" back width="wide">
        <div class="icons-overview">
            <p class="icons-overview__summary">
                {{ ICON_LIST.length }} · {{ t('iconsOverview.sources', counts) }}
            </p>

            <div class="icons-overview__groups">
                <section
                    v-for="group in groups"
                    :key="group.category"
                    class="icons-overview__group"
                    :aria-labelledby="'icons-' + group.category"
                >
                    <div class="icons-overview__head">
                        <SectionLabel
                            :id="'icons-' + group.category"
                            :label="t('categories.' + group.category)"
                            :count="group.icons.length"
                        />
                        <span class="icons-overview__family">{{ t('iconsOverview.family', { family: group.family }) }}</span>
                    </div>
                    <ul class="icons-overview__grid">
                        <li v-for="icon in group.icons" :key="icon.key" class="icons-overview__item">
                            <ProductImage :icon="icon.key" :category="group.category" />
                            <span class="icons-overview__name">{{ t('icons.' + icon.key) }}</span>
                            <span class="icons-overview__source" :class="'icons-overview__source--' + icon.kind">
                                {{ t(SOURCE_KEYS[icon.kind]) }}
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AppPage>
</template>

<style scoped>
.icons-overview {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding-bottom: var(--space-8);
}

.icons-overview__groups {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

/* Desktop: Kategorien in Spalten, damit alle Icons auf einen Blick passen */
@media (min-width: 64em) {
    .icons-overview__groups {
        display: block;
        columns: 3 var(--product-list-width);
        column-gap: var(--space-6);
    }

    .icons-overview__group {
        break-inside: avoid;
        padding-bottom: var(--space-2);
    }
}

.icons-overview__summary {
    padding: 0 var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
}

.icons-overview__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
}

.icons-overview__family {
    padding-right: var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-caption);
}

.icons-overview__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--icon-option-size), 1fr));
    gap: var(--space-3) var(--space-2);
    padding: var(--space-4) var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.icons-overview__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-1);
    min-width: 0;
    text-align: center;
}

.icons-overview__name {
    max-width: 100%;
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-caption);
    line-height: var(--line-height-label);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.icons-overview__source {
    color: var(--color-text-2);
    font-size: var(--font-size-caption);
    line-height: var(--line-height-label);
}

.icons-overview__source--own {
    color: var(--color-accent-text);
}
</style>
