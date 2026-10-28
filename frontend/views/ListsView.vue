<script setup>
/**
 * Listen: Startansicht (Designpaket 01) mit den aktiven Einkäufen als
 * Karten (Name, Datum, Herkunft, Fortschritt, erste Produkte) und „Neue
 * Liste“ unten im Daumenbereich. Eine neue Liste erscheint sofort und öffnet
 * sich direkt.
 *
 * Herkunft als Chip: „nur ich“ oder „Haushalt“; geteilte Listen folgen
 * mit Schritt 11. Unter den Karten führt „Archiv“ zu
 * den abgeschlossenen Einkäufen; „Neue Liste“ startet leer oder aus einem
 * früheren Einkauf.
 *
 * Ladezustand in Endhöhe: Das Gerät merkt sich die zuletzt geladene Anzahl
 * aktiver Listen (je Benutzer, `localStorage`) und zeigt so viele
 * Skeleton-Karten; bei 0 hält ein unsichtbarer leerer Zustand die Höhe.
 * So steht „Neue Liste“ schon beim Laden an seinem Platz.
 */
import { computed, ref, watch } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Archive, ChevronRight, ListChecks, Plus, TriangleAlert } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import ListCard from '@/components/lists/ListCard.vue';
import ListCardSkeleton from '@/components/lists/ListCardSkeleton.vue';
import ListFormSheet from '@/components/lists/ListFormSheet.vue';
import { createListWith, loadLists, useLists } from '@/composables/useLists.js';
import { loadProducts } from '@/composables/useProducts.js';
import { useSession } from '@/composables/useSession.js';
import { formatDayHeading, t } from '@/i18n/index.js';

const SKELETON_WIDTHS = [0.35, 0.25, 0.4, 0.3, 0.45];
const MAX_SKELETONS = 8;
const COUNT_KEY = 'pk.activeListCount.';
const ARCHIVE_KEY = 'pk.hasArchive.';

const ionRouter = useIonRouter();
const { overview, activeLists, archivedLists } = useLists();
const { user } = useSession();

function readCount() {
    try {
        const value = Number.parseInt(localStorage.getItem(COUNT_KEY + (user.value?.id ?? '')) ?? '', 10);

        return Number.isFinite(value) && value >= 0 ? Math.min(value, MAX_SKELETONS) : 1;
    } catch {
        return 1;
    }
}

const skeletonCount = ref(readCount());

// „Archiv“ steht beim Laden schon da, wenn es zuletzt da war (nichts springt)
function readHasArchive() {
    try {
        return localStorage.getItem(ARCHIVE_KEY + (user.value?.id ?? '')) === '1';
    } catch {
        return false;
    }
}

const hadArchive = ref(readHasArchive());

watch(
    () => (overview.status === 'ready' ? archivedLists.value.length > 0 : null),
    (hasArchive) => {
        if (hasArchive === null) {
            return;
        }

        try {
            localStorage.setItem(ARCHIVE_KEY + (user.value?.id ?? ''), hasArchive ? '1' : '0');
        } catch {
            // nur Bequemlichkeit für den Ladezustand
        }
    },
    { immediate: true }
);

watch(
    () => (overview.status === 'ready' ? activeLists.value.length : null),
    (count) => {
        if (count === null) {
            return;
        }

        try {
            localStorage.setItem(COUNT_KEY + (user.value?.id ?? ''), String(count));
        } catch {
            // nur Bequemlichkeit für den Ladezustand
        }
    },
    { immediate: true }
);

const today = formatDayHeading(Date.now());
const formOpen = ref(false);

// Skeleton nur beim ersten Laden; danach bleibt der bekannte Stand stehen
const loading = computed(() => overview.status === 'idle' || (overview.status === 'loading' && activeLists.value.length === 0));

function reload() {
    loadLists().catch((error) => console.error(error));
    loadProducts().catch((error) => console.error(error));
}

onIonViewWillEnter(reload);

function openList(list) {
    ionRouter.push('/lists/' + encodeURIComponent(list.id));
}

async function onCreate(fields) {
    const listId = await createListWith(fields);

    ionRouter.push('/lists/' + encodeURIComponent(listId));
}
</script>

<template>
    <AppPage :title="t('lists.title')" :subtitle="today" width="list">
        <div class="lists">
            <template v-if="loading && skeletonCount > 0">
                <SectionLabel class="lists__label" :label="t('lists.active')" />
                <ul class="lists__cards" aria-busy="true">
                    <li v-for="index in skeletonCount" :key="index">
                        <ListCardSkeleton :width="SKELETON_WIDTHS[(index - 1) % SKELETON_WIDTHS.length]" />
                    </li>
                </ul>
            </template>

            <!-- zuletzt keine Listen: Platz des leeren Zustands halten, ohne ihn zu zeigen -->
            <EmptyState
                v-else-if="loading"
                class="lists__placeholder"
                aria-hidden="true"
                :icon="ListChecks"
                :title="t('lists.emptyTitle')"
                :text="t('lists.emptyText')"
            />

            <EmptyState
                v-else-if="overview.status === 'error' && activeLists.length === 0"
                :icon="TriangleAlert"
                :title="t('lists.loadError')"
            >
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <EmptyState
                v-else-if="activeLists.length === 0"
                :icon="ListChecks"
                :title="t('lists.emptyTitle')"
                :text="t('lists.emptyText')"
            />

            <template v-else>
                <SectionLabel class="lists__label" :label="t('lists.active')" :count="activeLists.length" />
                <ul class="lists__cards">
                    <li v-for="list in activeLists" :key="list.id">
                        <ListCard :list="list" @open="openList(list)" />
                    </li>
                </ul>
            </template>

            <div v-if="loading && hadArchive" class="lists__archive lists__archive--skeleton" aria-hidden="true">
                <span class="lists__archive-icon"></span>
                <span class="lists__archive-text">
                    <span class="lists__archive-title">{{ t('lists.archive') }}</span>
                    <span class="lists__archive-hint">&nbsp;</span>
                </span>
            </div>

            <button
                v-else-if="archivedLists.length > 0"
                type="button"
                class="lists__archive"
                @click="ionRouter.push('/archive')"
            >
                <span class="lists__archive-icon">
                    <Archive aria-hidden="true" />
                </span>
                <span class="lists__archive-text">
                    <span class="lists__archive-title">{{ t('lists.archive') }}</span>
                    <span class="lists__archive-hint">{{ t('lists.archiveHint', { count: archivedLists.length }) }}</span>
                </span>
                <ChevronRight class="lists__archive-chevron" aria-hidden="true" />
            </button>

            <AppButton class="lists__new" block @click="formOpen = true">
                <Plus aria-hidden="true" />
                {{ t('lists.new') }}
            </AppButton>
        </div>

        <ListFormSheet v-model:open="formOpen" @submit="onCreate" />
    </AppPage>
</template>

<style scoped>
.lists {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: 0 var(--page-gutter);
}

.lists__label {
    padding-top: var(--space-2);
    padding-bottom: 0;
}

.lists__cards {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.lists__new {
    margin-top: var(--space-3);
}

.lists__placeholder {
    visibility: hidden;
}

.lists__archive {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-3) var(--space-2) var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    text-align: left;
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .lists__archive:hover {
        background: var(--color-surface-2);
    }
}

.lists__archive--skeleton .lists__archive-title {
    align-self: flex-start;
    color: transparent;
    border-radius: var(--radius-xs);
    background: var(--color-skeleton);
}

.lists__archive--skeleton .lists__archive-icon {
    background: var(--color-skeleton);
}

.lists__archive-icon {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-surface-2);
    color: var(--color-text-2);
}

.lists__archive-icon :deep(svg),
.lists__archive-chevron {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.lists__archive-chevron {
    color: var(--color-text-3);
}

.lists__archive-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.lists__archive-title {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.lists__archive-hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
