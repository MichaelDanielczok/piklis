<script setup>
/**
 * Listen: Startansicht (Designpaket 01) mit den aktiven Einkäufen als
 * Karten (Name, Datum, Herkunft, Fortschritt, erste Produkte) und „Neue
 * Liste“ unten im Daumenbereich. Eine neue Liste erscheint sofort und öffnet
 * sich direkt.
 *
 * Herkunft vorerst immer „nur ich“; Haushaltslisten (Schritt 9), geteilte
 * Listen (Schritt 11) und das Archiv (Schritt 7) folgen.
 *
 * Ladezustand in Endhöhe: Das Gerät merkt sich die zuletzt geladene Anzahl
 * aktiver Listen (je Benutzer, `localStorage`) und zeigt so viele
 * Skeleton-Karten; bei 0 hält ein unsichtbarer leerer Zustand die Höhe.
 * So steht „Neue Liste“ schon beim Laden an seinem Platz.
 */
import { computed, ref, watch } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { ListChecks, Plus, TriangleAlert } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import ListCard from '@/components/lists/ListCard.vue';
import ListCardSkeleton from '@/components/lists/ListCardSkeleton.vue';
import ListFormSheet from '@/components/lists/ListFormSheet.vue';
import { createList, loadLists, useLists } from '@/composables/useLists.js';
import { loadProducts } from '@/composables/useProducts.js';
import { useSession } from '@/composables/useSession.js';
import { formatDayHeading, t } from '@/i18n/index.js';

const SKELETON_WIDTHS = [0.35, 0.25, 0.4, 0.3, 0.45];
const MAX_SKELETONS = 8;
const COUNT_KEY = 'pk.activeListCount.';

const ionRouter = useIonRouter();
const { overview, activeLists } = useLists();
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
    const listId = await createList(fields);

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
</style>
