<script setup>
/**
 * Einladung zu einer Liste annehmen (`/invite/:token`, Link aus „Liste
 * teilen“). Ohne Anmeldung führt der Router zur Anmeldung und danach
 * hierher zurück; ein Konto lässt sich über den Link nicht anlegen.
 *
 * - Gültig: wer einlädt, Name und Datum der Liste, was man darf;
 *   „Annehmen“ (danach dauerhaft in der Übersicht, der Link ist verbraucht)
 *   oder „Nicht jetzt“.
 * - Schon Zugriff (eigene, Haushalts- oder schon geteilte Liste): „Liste
 *   öffnen“, der Link bleibt unverbraucht.
 * - Benutzt, abgelaufen, zurückgezogen, Liste gelöscht: „Link nicht mehr
 *   gültig, bitte um einen neuen“.
 *
 * Die Karte hat beim Laden dieselbe Höhe (Platzhalter in den Textzeilen).
 */
import { computed, ref, shallowRef } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { useRoute } from 'vue-router';
import { Link2Off, ListChecks, TriangleAlert } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { api, ApiError } from '@/api/index.js';
import { acceptListInvite } from '@/composables/useLists.js';
import { showToast } from '@/composables/useToast.js';
import { formatShopDate } from '@/utils/shopDate.js';
import { formatDate, t } from '@/i18n/index.js';

const route = useRoute();
const ionRouter = useIonRouter();

// Ionic hält Seiten im Verlauf; das Token gehört zu dieser Seite
const token = String(route.params.token ?? '');

/** loading · ready · invalid · error */
const status = ref('loading');
const invite = shallowRef(null);
const access = ref(null);
const pending = ref(false);

const loaded = computed(() => status.value === 'ready' && invite.value !== null);
const listMeta = computed(() => {
    const date = loaded.value ? formatShopDate(invite.value.list.shopDate) : '';

    return date ? t('invite.listWithDate', { date }) : t('invite.list');
});

async function load() {
    status.value = 'loading';

    try {
        const data = await api.getInvite(token);

        invite.value = data.invite;
        access.value = data.access ?? null;
        status.value = 'ready';
    } catch (error) {
        handleError(error);
    }
}

function handleError(error) {
    if (error instanceof ApiError && error.code === 'invite_invalid') {
        status.value = 'invalid';
        return;
    }

    if (!(error instanceof ApiError) || error.status === 0 || error.status >= 500) {
        console.error(error);
    }

    status.value = 'error';
}

onIonViewWillEnter(load);

function openList(listId) {
    ionRouter.navigate('/lists/' + encodeURIComponent(listId), 'forward', 'replace');
}

async function accept() {
    if (pending.value || !loaded.value) {
        return;
    }

    pending.value = true;

    try {
        const result = await acceptListInvite(token);

        if (result.status === 'accepted') {
            showToast(t('invite.accepted', { name: result.list.name }), { icon: 'info' });
        }

        openList(result.list.id);
    } catch (error) {
        handleError(error);

        if (status.value === 'error') {
            status.value = 'ready';
            showToast(error instanceof ApiError && error.status === 0 ? t('common.offline') : t('common.error'), {
                icon: 'notgot'
            });
        }
    } finally {
        pending.value = false;
    }
}

function later() {
    ionRouter.navigate('/', 'root', 'replace');
}
</script>

<template>
    <AppPage :title="t('invite.title')" back width="narrow">
        <div class="invite">
            <EmptyState
                v-if="status === 'invalid'"
                :icon="Link2Off"
                :title="t('invite.invalidTitle')"
                :text="t('invite.invalidText')"
            >
                <AppButton variant="secondary" @click="later">{{ t('lists.toOverview') }}</AppButton>
            </EmptyState>

            <EmptyState v-else-if="status === 'error'" :icon="TriangleAlert" :title="t('invite.loadError')">
                <AppButton variant="secondary" @click="load">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <section v-else class="invite__card" :aria-busy="loaded ? 'false' : 'true'">
                <span class="invite__icon" aria-hidden="true"><ListChecks /></span>

                <p class="invite__from" :class="{ 'invite__skeleton': !loaded }">
                    <template v-if="loaded">
                        {{ access ? t('invite.fromAccess', { name: invite.invitedBy.name }) : t('invite.from', { name: invite.invitedBy.name }) }}
                    </template>
                </p>
                <h2 class="invite__name" :class="{ 'invite__skeleton invite__skeleton--wide': !loaded }">
                    <template v-if="loaded">{{ invite.list.name }}</template>
                </h2>
                <p class="invite__meta" :class="{ 'invite__skeleton': !loaded }">
                    <template v-if="loaded">{{ listMeta }}</template>
                </p>

                <p class="invite__text">
                    {{ access ? t('invite.alreadyText') : t('invite.rights') }}
                </p>

                <div class="invite__actions">
                    <template v-if="access">
                        <AppButton block @click="openList(invite.list.id)">{{ t('invite.open') }}</AppButton>
                    </template>
                    <template v-else>
                        <AppButton block :disabled="!loaded || pending" @click="accept">{{ t('invite.accept') }}</AppButton>
                        <AppButton block variant="secondary" :disabled="pending" @click="later">{{ t('invite.later') }}</AppButton>
                    </template>
                </div>

                <p class="invite__hint">
                    {{ loaded && !access ? t('invite.validUntil', { date: formatDate(invite.expiresAt) }) : t('invite.noAccount') }}
                </p>
            </section>
        </div>
    </AppPage>
</template>

<style scoped>
.invite {
    padding: var(--space-2) var(--page-gutter) var(--space-8);
}

.invite__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-6) var(--space-4) var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    background: var(--color-surface-1);
    text-align: center;
}

.invite__icon {
    display: grid;
    place-items: center;
    width: 3.5rem;
    height: 3.5rem;
    margin-bottom: var(--space-2);
    border-radius: 50%;
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
}

.invite__icon svg {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.invite__from,
.invite__meta {
    min-height: calc(var(--font-size-text) * var(--line-height-text));
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.invite__name {
    min-height: var(--line-height-h1);
    max-width: 100%;
    overflow-wrap: anywhere;
    color: var(--color-text-1);
    font-size: var(--font-size-h1);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h1);
}

.invite__skeleton {
    width: 9rem;
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.invite__skeleton--wide {
    width: 13rem;
}

.invite__text {
    max-width: 30rem;
    padding: var(--space-3) 0 var(--space-2);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.invite__actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    width: 100%;
}

.invite__hint {
    padding-top: var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
