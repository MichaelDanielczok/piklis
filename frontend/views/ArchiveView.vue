<script setup>
/**
 * Archiv: abgeschlossene Einkäufe, zuletzt abgeschlossen zuerst (aus der
 * Listenübersicht über „Archiv“). Je Einkauf Name und Datum, wann
 * abgeschlossen, wie viel erledigt und nicht bekommen war und ob danach noch
 * etwas geändert wurde. Antippen öffnet den Einkauf zum Ansehen; dort
 * „Wieder öffnen“ und „Neue Liste daraus“.
 */
import { computed } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Archive, ChevronRight, TriangleAlert } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { loadLists, useLists } from '@/composables/useLists.js';
import { formatDay, formatShopDate } from '@/utils/shopDate.js';
import { t } from '@/i18n/index.js';

const SKELETON_ROWS = 3;

const ionRouter = useIonRouter();
const { overview, archivedLists } = useLists();

const loading = computed(() => overview.status === 'idle' || (overview.status === 'loading' && archivedLists.value.length === 0));
const subtitle = computed(() => (loading.value ? ' ' : t('archive.subtitle', { count: archivedLists.value.length })));

function reload() {
    loadLists().catch((error) => console.error(error));
}

onIonViewWillEnter(reload);

function titleOf(list) {
    const date = formatShopDate(list.shopDate);

    return date ? list.name + ' · ' + date : list.name;
}

function summaryOf(list) {
    const parts = [t('archive.completedOn', { date: formatDay(list.completedAt) })];

    parts.push(t('archive.summary', { done: list.counts.done, total: list.counts.total }));


    return parts.join(' · ');
}

function openList(list) {
    ionRouter.push('/lists/' + encodeURIComponent(list.id));
}
</script>

<template>
    <AppPage :title="t('archive.title')" :subtitle="subtitle" back back-to="/" width="list">
        <div class="archive">
            <ul v-if="loading" class="archive__rows" aria-busy="true">
                <li v-for="index in SKELETON_ROWS" :key="index" class="archive__row archive__row--skeleton" aria-hidden="true">
                    <span class="archive__icon"></span>
                    <span class="archive__text">
                        <span class="archive__skeleton archive__skeleton--title"></span>
                        <span class="archive__skeleton"></span>
                        <span class="archive__skeleton archive__skeleton--short"></span>
                    </span>
                </li>
            </ul>

            <EmptyState
                v-else-if="overview.status === 'error' && archivedLists.length === 0"
                :icon="TriangleAlert"
                :title="t('lists.loadError')"
            >
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <EmptyState
                v-else-if="archivedLists.length === 0"
                :icon="Archive"
                :title="t('archive.emptyTitle')"
                :text="t('archive.emptyText')"
            />

            <ul v-else class="archive__rows">
                <li v-for="list in archivedLists" :key="list.id">
                    <button type="button" class="archive__row" :aria-label="t('archive.open', { name: titleOf(list) })" @click="openList(list)">
                        <span class="archive__icon">
                            <Archive aria-hidden="true" />
                        </span>
                        <span class="archive__text">
                            <span class="archive__title">{{ titleOf(list) }}</span>
                            <span class="archive__meta">{{ summaryOf(list) }}</span>
                            <span class="archive__flags">
                                <span v-if="list.counts.notGot > 0" class="archive__flag archive__flag--not-got">
                                    {{ t('archive.notGot', { count: list.counts.notGot }) }}
                                </span>
                                <span v-if="list.counts.open > 0" class="archive__flag">
                                    {{ t('archive.leftOpen', { count: list.counts.open }) }}
                                </span>
                                <span
                                    v-if="list.counts.notGot === 0 && list.counts.open === 0"
                                    class="archive__flag archive__flag--done"
                                >
                                    {{ t('archive.allDone') }}
                                </span>
                                <span v-if="list.changedAfterCompletion" class="archive__flag archive__flag--changed">
                                    {{ t('archive.changedAfter') }}
                                </span>
                            </span>
                        </span>
                        <ChevronRight class="archive__chevron" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </div>
    </AppPage>
</template>

<style scoped>
.archive {
    padding: 0 var(--page-gutter);
}

.archive__rows {
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.archive__rows > li + li {
    border-top: 1px solid var(--color-border-subtle);
}

.archive__row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--row-min-height);
    padding: var(--space-3) var(--space-3) var(--space-3) var(--space-4);
    text-align: left;
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    button.archive__row:hover {
        background: var(--color-surface-2);
    }
}

.archive__row:focus-visible {
    outline-offset: calc(-1 * var(--focus-ring-width));
}

.archive__icon {
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

.archive__row--skeleton .archive__icon {
    background: var(--color-skeleton);
}

.archive__icon :deep(svg),
.archive__chevron {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.archive__chevron {
    color: var(--color-text-3);
}

.archive__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
}

.archive__title {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
}

.archive__meta {
    overflow: hidden;
    color: var(--color-text-2);
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.archive__flags {
    display: flex;
    flex-wrap: wrap;
    column-gap: var(--space-3);
}

.archive__flag {
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-note);
}

.archive__flag--done {
    color: var(--color-done);
}

.archive__flag--not-got {
    color: var(--color-notgot);
}

.archive__flag--changed {
    color: var(--color-unsynced);
}

.archive__skeleton {
    display: block;
    width: 60%;
    height: var(--line-height-note);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.archive__skeleton--short {
    width: 30%;
}

.archive__skeleton--title {
    width: 40%;
    height: var(--line-height-name);
    background: var(--color-skeleton);
}
</style>
