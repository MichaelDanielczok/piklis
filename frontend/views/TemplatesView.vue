<script setup>
/**
 * Vorlagen: Übersicht der persönlichen und Haushaltsvorlagen (nicht
 * entworfen, im Stil der Listenübersicht). Karten mit Name, Herkunft
 * („nur ich“ oder „Haushalt“), Zahl der Produkte und den ersten Produkten,
 * nach Name sortiert. „Neue Vorlage“ unten legt eine leere an; meist
 * entstehen Vorlagen aber aus einer Liste („…“ › „Als Vorlage speichern“).
 *
 * Ladezustand in Endhöhe wie bei den Listen: so viele Skeleton-Karten wie
 * zuletzt auf diesem Gerät (`pk.templateCount.<user>`, höchstens 8). Kein
 * Live-Abgleich; die Übersicht lädt beim Öffnen.
 *
 * Offline: Vorlagen sind laut Entscheidung nur online und liegen nicht im
 * lokalen Speicher. Ohne Verbindung bleibt ein in dieser Sitzung geladener
 * Stand stehen (mit Hinweis), sonst eine klare Meldung statt eines Fehlers.
 */
import { logError } from '@/api/index.js';
import { computed, ref, watch } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { CloudOff, Files, Plus, TriangleAlert } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import ListCard from '@/components/lists/ListCard.vue';
import ListCardSkeleton from '@/components/lists/ListCardSkeleton.vue';
import TemplateFormSheet from '@/components/templates/TemplateFormSheet.vue';
import OfflineNote from '@/components/ui/OfflineNote.vue';
import { useConnection } from '@/composables/useConnection.js';
import { loadProducts } from '@/composables/useProducts.js';
import { useSession } from '@/composables/useSession.js';
import { createTemplate, loadTemplates, startEditing, useTemplates } from '@/composables/useTemplates.js';
import { t } from '@/i18n/index.js';

const SKELETON_WIDTHS = [0.4, 0.3, 0.45, 0.35, 0.5];
const MAX_SKELETONS = 8;
const COUNT_KEY = 'pk.templateCount.';

const ionRouter = useIonRouter();
const { overview } = useTemplates();
const { online } = useConnection();
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
const formOpen = ref(false);
const pending = ref(false);
const formError = ref('');

watch(
    () => (overview.status === 'ready' ? overview.templates.length : null),
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

const loading = computed(() => overview.status === 'idle' || (overview.status === 'loading' && overview.templates.length === 0));
function reload() {
    loadTemplates().catch(logError);
    loadProducts().catch(logError);
}

onIonViewWillEnter(reload);

function openTemplate(template) {
    ionRouter.push('/templates/' + encodeURIComponent(template.id));
}

function openForm() {
    formError.value = '';
    formOpen.value = true;
}

async function onCreate(fields) {
    pending.value = true;
    formError.value = '';

    try {
        const template = await createTemplate({ name: fields.name, householdId: fields.householdId });

        formOpen.value = false;
        startEditing(template.id);
        ionRouter.push('/templates/' + encodeURIComponent(template.id));
    } catch (error) {
        console.error(error);
        formError.value = error?.status === 0 ? t('templateSave.offline') : t('templateSave.error');
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <AppPage :title="t('templates.title')" :subtitle="t('templates.subtitle')" width="list">
        <div class="templates">
            <OfflineNote v-if="!online && overview.templates.length > 0" :text="t('templates.offlineNote')" />

            <template v-if="loading && skeletonCount > 0">
                <SectionLabel class="templates__label" :label="t('templates.title')" />
                <ul class="templates__cards" aria-busy="true">
                    <li v-for="index in skeletonCount" :key="index">
                        <ListCardSkeleton :width="SKELETON_WIDTHS[(index - 1) % SKELETON_WIDTHS.length]" />
                    </li>
                </ul>
            </template>

            <!-- zuletzt keine Vorlagen: Platz des leeren Zustands halten, ohne ihn zu zeigen -->
            <EmptyState
                v-else-if="loading"
                class="templates__placeholder"
                aria-hidden="true"
                :icon="Files"
                :title="t('templates.emptyTitle')"
                :text="t('templates.emptyText')"
            />

            <EmptyState
                v-else-if="overview.status === 'error' && overview.templates.length === 0 && !online"
                :icon="CloudOff"
                :title="t('templates.offlineTitle')"
                :text="t('templates.offlineText')"
            >
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <EmptyState
                v-else-if="overview.status === 'error' && overview.templates.length === 0"
                :icon="TriangleAlert"
                :title="t('templates.loadError')"
            >
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <EmptyState
                v-else-if="overview.templates.length === 0"
                :icon="Files"
                :title="t('templates.emptyTitle')"
                :text="t('templates.emptyText')"
            />

            <template v-else>
                <SectionLabel class="templates__label" :label="t('templates.title')" :count="overview.templates.length" />
                <ul class="templates__cards">
                    <li v-for="template in overview.templates" :key="template.id">
                        <ListCard :list="template" variant="template" @open="openTemplate(template)" />
                    </li>
                </ul>
            </template>

            <AppButton class="templates__new" block @click="openForm">
                <Plus aria-hidden="true" />
                {{ t('templates.new') }}
            </AppButton>
            <p v-if="overview.templates.length > 0" class="templates__hint">{{ t('templates.fromListHint') }}</p>
        </div>

        <TemplateFormSheet v-model:open="formOpen" :pending="pending" :error="formError" @submit="onCreate" />
    </AppPage>
</template>

<style scoped>
.templates {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: 0 var(--page-gutter);
}

.templates__label {
    padding-top: var(--space-2);
    padding-bottom: 0;
}

.templates__cards {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.templates__new {
    margin-top: var(--space-3);
}

.templates__placeholder {
    visibility: hidden;
}

.templates__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-align: center;
}
</style>
