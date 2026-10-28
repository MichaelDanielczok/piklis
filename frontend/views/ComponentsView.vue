<script setup>
/**
 * Musterseite der Grundkomponenten (nur im Entwicklungsserver registriert,
 * siehe router.js): Produktbild, Ersatzicons, Listenzeilen in allen
 * Zuständen, Mengen, Hinzufügen-Kacheln, Abschnittslabel, Sheet, Skeleton,
 * Toast. Die Beispieldaten leben nur hier; die Fotos sind Ausschnitte der
 * gezeichneten Platzhalter aus dem Designpaket.
 */
import { reactive, ref } from 'vue';
import AppPage from '@/components/layout/AppPage.vue';
import AddTile from '@/components/list/AddTile.vue';
import ListRow from '@/components/list/ListRow.vue';
import QuantityLabel from '@/components/list/QuantityLabel.vue';
import RowGroup from '@/components/list/RowGroup.vue';
import SkeletonRow from '@/components/list/SkeletonRow.vue';
import ProductIcon from '@/components/products/ProductIcon.vue';
import ProductImage from '@/components/products/ProductImage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import { showToast } from '@/composables/useToast.js';
import { FALLBACK_ICONS } from '@/icons/sprite.js';
import { t } from '@/i18n/index.js';
import milk from '@/dev/samples/milch.webp';
import tomatoes from '@/dev/samples/tomaten.webp';
import bananas from '@/dev/samples/bananen.webp';
import creamCheese from '@/dev/samples/frischkaese.webp';

// Auswahl für die Musterseite; alle Icons zeigt /icons
const SAMPLE_ICONS = ['apfel', 'karotte', 'brot', 'milch', 'kaese', 'fleisch', 'fisch', 'nudeln', 'dose', 'tiefkuehl', 'schokolade', 'flasche', 'kaffee', 'mehl', 'toilettenpapier', 'waschmittel'];

const rows = reactive([
    { id: 1, name: 'Tomaten Rispe', amount: 500, unit: 'g', imageUrl: tomatoes, status: 'open' },
    { id: 2, name: 'Milch 1,5 %', amount: 2, unit: 'pack', imageUrl: milk, note: t('components.sampleNote'), status: 'open' },
    { id: 3, name: 'Butter', amount: 1, unit: 'piece', icon: 'butter', status: 'open' },
    { id: 4, name: 'Freilandeier Gr. M', amount: 10, unit: 'piece', icon: 'eier', status: 'open' },
    { id: 5, name: 'Frischkäse', amount: 2, unit: 'pack', imageUrl: creamCheese, note: t('components.sampleNote2'), status: 'open', unsynced: true },
    { id: 6, name: 'Fusilli', amount: 2, unit: 'pack', icon: 'nudeln', status: 'open', unsynced: true },
    { id: 7, name: 'Gouda jung am Stück', amount: 400, unit: 'g', icon: 'kaese', status: 'notGot' },
    { id: 8, name: 'Bananen', amount: 6, unit: 'piece', imageUrl: bananas, status: 'done' },
    { id: 9, name: 'Toilettenpapier', amount: 1, unit: null, icon: 'toilettenpapier', status: 'done' }
]);

const tiles = reactive([
    { id: 1, name: 'Milch 1,5 %', imageUrl: milk, unit: 'pack', quantity: 2 },
    { id: 2, name: 'Vollkornbrot', icon: 'brot', unit: 'piece', quantity: 0 },
    { id: 3, name: 'Gouda jung am Stück', icon: 'kaese', unit: 'piece', quantity: 1 },
    { id: 4, name: 'Spülmaschinentabs', icon: 'waschmittel', unit: 'pack', quantity: 0 },
    { id: 5, name: 'Kartoffeln festkochend', icon: 'gemuese', unit: 'kg', quantity: 0 },
    { id: 6, name: 'Bananen', imageUrl: bananas, unit: null, quantity: 0 }
]);

const quantities = [
    { amount: 2, unit: null },
    { amount: 1, unit: 'pack' },
    { amount: 3, unit: 'pack' },
    { amount: 1, unit: 'jar' },
    { amount: 2, unit: 'jar' },
    { amount: 6, unit: 'piece' },
    { amount: 500, unit: 'g' },
    { amount: 1.5, unit: 'kg' },
    { amount: 0.75, unit: 'l' }
];

const sheetOpen = ref(false);

function byStatus(status) {
    return rows.filter((row) => row.status === status);
}

function toggle(row) {
    const wasOpen = row.status === 'open';
    row.status = wasOpen ? 'done' : 'open';

    if (wasOpen) {
        showToast(t('row.checked', { name: row.name }), {
            icon: 'done',
            action: {
                text: t('common.undo'),
                handler: () => {
                    row.status = 'open';
                }
            }
        });
    }
}

function details(row) {
    showToast(row.name, { icon: 'info' });
}

function demoToast() {
    showToast(t('row.checked', { name: 'Bananen' }), {
        icon: 'done',
        action: {
            text: t('common.undo'),
            handler: () => showToast(t('components.toastUndone'))
        }
    });
}
</script>

<template>
    <AppPage :title="t('components.title')" :subtitle="t('components.intro')" back width="list">
        <div class="patterns">
            <section class="patterns__section" aria-labelledby="patterns-image">
                <SectionLabel id="patterns-image" :label="t('components.productImage')" />
                <p class="patterns__hint">{{ t('components.productImageHint') }}</p>
                <div class="patterns__images">
                    <ProductImage :image-url="milk" />
                    <ProductImage icon="milch" category="dairy" />
                    <ProductImage :image-url="tomatoes" size="tile" />
                    <ProductImage icon="karotte" category="vegetables" size="tile" />
                    <ProductImage :image-url="bananas" size="lg" />
                    <ProductImage icon="banane" category="fruit" size="lg" />
                    <ProductImage :image-url="bananas" muted />
                    <ProductImage icon="banane" category="fruit" muted />
                    <ProductImage image-url="/gibt-es-nicht.webp" icon="apfel" />
                </div>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-icons">
                <SectionLabel id="patterns-icons" :label="t('components.icons')" :count="FALLBACK_ICONS.length" />
                <p class="patterns__hint">
                    {{ t('components.iconsHint') }}
                    <RouterLink to="/icons">{{ t('components.iconsLink') }}</RouterLink>
                </p>
                <div class="patterns__icons-raw" aria-hidden="true">
                    <ProductIcon v-for="name in SAMPLE_ICONS" :key="name" :name="name" class="patterns__icon-raw" />
                </div>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-rows">
                <SectionLabel id="patterns-rows" :label="t('components.rows')" />

                <SectionLabel :label="t('components.sectionOpen')" :count="byStatus('open').length" :level="3" />
                <RowGroup>
                    <ListRow
                        v-for="row in byStatus('open')"
                        :key="row.id"
                        v-bind="row"
                        @toggle="toggle(row)"
                        @details="details(row)"
                    />
                </RowGroup>

                <SectionLabel :label="t('components.sectionNotGot')" :count="byStatus('notGot').length" :level="3" />
                <RowGroup>
                    <ListRow
                        v-for="row in byStatus('notGot')"
                        :key="row.id"
                        v-bind="row"
                        @transfer="sheetOpen = true"
                        @details="details(row)"
                    />
                </RowGroup>

                <SectionLabel :label="t('components.sectionDone')" :count="byStatus('done').length" :level="3" />
                <RowGroup>
                    <ListRow
                        v-for="row in byStatus('done')"
                        :key="row.id"
                        v-bind="row"
                        @toggle="toggle(row)"
                        @details="details(row)"
                    />
                </RowGroup>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-skeleton">
                <SectionLabel id="patterns-skeleton" :label="t('components.skeleton')" />
                <RowGroup>
                    <SkeletonRow :width="0.7" />
                    <SkeletonRow :width="0.45" />
                    <SkeletonRow :width="0.6" />
                </RowGroup>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-quantities">
                <SectionLabel id="patterns-quantities" :label="t('components.quantities')" />
                <ul class="patterns__quantities">
                    <li v-for="(quantity, index) in quantities" :key="index">
                        <QuantityLabel :amount="quantity.amount" :unit="quantity.unit" />
                    </li>
                </ul>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-tiles">
                <SectionLabel id="patterns-tiles" :label="t('components.tiles')" />
                <ul class="patterns__tiles">
                    <li v-for="tile in tiles" :key="tile.id">
                        <AddTile
                            v-model="tile.quantity"
                            :name="tile.name"
                            :image-url="tile.imageUrl"
                            :icon="tile.icon"
                            :unit="tile.unit"
                        />
                    </li>
                </ul>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-labels">
                <SectionLabel id="patterns-labels" :label="t('components.labels')" />
                <div class="patterns__labels">
                    <SectionLabel :label="t('components.sectionOpen')" :level="3" />
                    <SectionLabel :label="t('components.sectionDone')" :count="8" :level="3" />
                </div>
            </section>

            <section class="patterns__section" aria-labelledby="patterns-sheet">
                <SectionLabel id="patterns-sheet" :label="t('components.sheet') + ' · ' + t('components.toast')" />
                <div class="patterns__buttons">
                    <AppButton @click="sheetOpen = true">{{ t('components.openSheet') }}</AppButton>
                    <AppButton variant="secondary" @click="demoToast">{{ t('components.showToast') }}</AppButton>
                </div>
            </section>
        </div>

        <AppSheet v-model:open="sheetOpen" :title="t('components.sheetTitle')" :subtitle="t('components.intro')">
            <p>{{ t('components.sheetText') }}</p>
            <RowGroup class="patterns__sheet-rows">
                <ListRow v-bind="rows[6]" />
                <ListRow v-bind="rows[1]" />
            </RowGroup>
            <template #footer>
                <AppButton variant="secondary" @click="sheetOpen = false">{{ t('common.close') }}</AppButton>
                <AppButton @click="sheetOpen = false">OK</AppButton>
            </template>
        </AppSheet>
    </AppPage>
</template>

<style scoped>
.patterns {
    display: flex;
    flex-direction: column;
    gap: var(--space-6);
    padding: 0 var(--list-gutter);
}

.patterns__section {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.patterns__hint {
    padding: 0 var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
}

.patterns__hint a {
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
}

.patterns__images {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.patterns__icons-raw {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    color: var(--color-text-1);
}

.patterns__icon-raw {
    width: var(--space-8);
    height: var(--space-8);
}

.patterns__quantities {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2) var(--space-5);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.patterns__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--tile-min-width), 1fr));
    gap: var(--space-3);
}

.patterns__labels {
    padding: 0 0 var(--space-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.patterns__buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    padding: 0 var(--space-2);
}

.patterns__sheet-rows {
    margin-top: var(--space-4);
}

@media (min-width: 64em) {
    .patterns {
        padding: 0 var(--page-gutter);
    }
}
</style>
