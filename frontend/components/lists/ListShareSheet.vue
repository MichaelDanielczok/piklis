<script setup>
/**
 * Sheet „Liste teilen“ (Schritt 11, im Designpaket nicht entworfen, im Stil
 * des Haushalts):
 *
 * - **Besitzer und Mitglieder:** „Link erstellen“ erzeugt einen
 *   Einladungslink (einmal verwendbar, 7 Tage gültig) und öffnet gleich das
 *   Teilen-Menü des Geräts; ohne Teilen-Menü wird er kopiert. Klappt beides
 *   nicht (Safari verlangt dafür ein frisches Tippen), stehen „Teilen“ und
 *   „Kopieren“ am Link. Darunter die Teilnehmer mit „Entfernen“ (Rückfrage
 *   im selben Sheet) und die offenen Links mit „Zurückziehen“.
 * - **Gast** (`list.role === 'guest'`): wer geteilt hat, was man darf, und
 *   „Liste verlassen“ (Rückfrage).
 *
 * Das Token gibt es nur beim Erzeugen; ein späterer Blick zeigt offene
 * Links ohne Adresse.
 *
 * v-model:open · `list` · Emits: left(name) (Gast hat die Liste verlassen)
 */
import { computed, ref, shallowRef, watch } from 'vue';
import { Copy, Link2, Link2Off, LogOut, Share2, TriangleAlert, UserMinus } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import UserAvatar from '@/components/ui/UserAvatar.vue';
import { api, ApiError } from '@/api/index.js';
import { useSession } from '@/composables/useSession.js';
import { showToast } from '@/composables/useToast.js';
import { formatDate, t } from '@/i18n/index.js';

const PARTICIPANTS_KEY = 'pk.shareRows';

const props = defineProps({
    list: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['left']);
const open = defineModel('open', { type: Boolean, default: false });

const { user } = useSession();

const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

const isGuest = computed(() => props.list.role === 'guest');
const sharedByName = computed(() => props.list.sharedBy?.name ?? '');

/** idle · loading · ready · error */
const status = ref('idle');
const shares = shallowRef([]);
const invites = shallowRef([]);
const created = shallowRef(null);
const pending = ref(false);
const confirm = shallowRef(null);
const skeletonRows = ref(1);

const link = computed(() => (created.value ? inviteUrl(created.value.token) : ''));

const sheetTitle = computed(() => {
    if (confirm.value?.type === 'remove') {
        return t('share.removeTitle', { name: confirm.value.share.name });
    }

    if (confirm.value?.type === 'leave') {
        return t('share.leaveTitle');
    }

    return isGuest.value ? props.list.name : t('share.title');
});

const sheetSubtitle = computed(() => {
    if (confirm.value) {
        return '';
    }

    return isGuest.value ? t('lists.sharedBy', { name: sharedByName.value }) : props.list.name;
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    confirm.value = null;
    created.value = null;

    if (!isGuest.value) {
        load();
    }
});

/** Adresse der App mit dem Token; im Entwicklungsserver dessen Adresse. */
function inviteUrl(token) {
    const { origin, pathname } = window.location;

    return origin + pathname + '#/invite/' + encodeURIComponent(token);
}

function readSkeletonRows() {
    try {
        const value = Number(localStorage.getItem(PARTICIPANTS_KEY));

        return Number.isInteger(value) && value >= 0 && value <= 6 ? value : 1;
    } catch {
        return 1;
    }
}

function rememberRows(count) {
    try {
        localStorage.setItem(PARTICIPANTS_KEY, String(Math.min(count, 6)));
    } catch {
        // nur Bequemlichkeit
    }
}

function apply(data) {
    shares.value = data.shares ?? [];
    invites.value = data.invites ?? [];
    status.value = 'ready';
    rememberRows(shares.value.length + invites.value.length);
}

async function load() {
    if (status.value !== 'ready') {
        skeletonRows.value = readSkeletonRows();
        status.value = 'loading';
    }

    try {
        apply(await api.getListShares(props.list.id));
    } catch (error) {
        logUnexpected(error);

        if (status.value !== 'ready') {
            status.value = 'error';
        }
    }
}

/** Erwartete Antworten (4xx) zeigt das Sheet; nur Unerwartetes in die Konsole. */
function logUnexpected(failure) {
    if (!(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500) {
        console.error(failure);
    }
}

function errorText(error) {
    if (error instanceof ApiError && error.status === 0) {
        return t('common.offline');
    }

    const key = 'share.errors.' + (error?.code ?? '');
    const text = t(key);

    return text === key ? t('common.error') : text;
}

function shareText() {
    return t('share.message', { name: user.value?.name ?? '', list: props.list.name });
}

async function createLink() {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        const result = await api.createListInvite(props.list.id);

        created.value = { token: result.token, id: result.invite.id, expiresAt: result.invite.expiresAt };
        invites.value = [...invites.value, result.invite];
        rememberRows(shares.value.length + invites.value.length);
    } catch (error) {
        logUnexpected(error);
        showToast(errorText(error), { icon: 'notgot' });
        return;
    } finally {
        pending.value = false;
    }

    // gleich weitergeben; klappt das ohne frisches Tippen nicht, bleiben die Knöpfe am Link
    if (canShare) {
        await shareLink(true);
    } else {
        await copyLink(true);
    }
}

async function shareLink(quiet = false) {
    try {
        await navigator.share({ title: t('share.shareTitle'), text: shareText(), url: link.value });
    } catch (error) {
        // Abbrechen im Teilen-Menü ist kein Fehler; ohne Tippen verweigert Safari (NotAllowedError)
        if (error?.name !== 'AbortError' && error?.name !== 'NotAllowedError' && !quiet) {
            console.error(error);
            copyLink();
        }
    }
}

async function copyLink(quiet = false) {
    try {
        await navigator.clipboard.writeText(shareText() + ' ' + link.value);
        showToast(t('share.copied'), { icon: 'info' });
    } catch (error) {
        if (!quiet) {
            console.error(error);
            showToast(t('common.error'), { icon: 'notgot' });
        }
    }
}

async function withdraw(invite) {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        apply(await api.withdrawListInvite(props.list.id, invite.id));

        if (created.value?.id === invite.id) {
            created.value = null;
        }

        showToast(t('share.withdrawn'), { icon: 'info' });
    } catch (error) {
        logUnexpected(error);
        showToast(errorText(error), { icon: 'notgot' });
        load();
    } finally {
        pending.value = false;
    }
}

async function confirmAction() {
    const action = confirm.value;

    if (!action || pending.value) {
        return;
    }

    pending.value = true;

    try {
        if (action.type === 'remove') {
            apply(await api.removeListShare(props.list.id, action.share.id));
            showToast(t('share.removed', { name: action.share.name }), { icon: 'info' });
            confirm.value = null;
        } else {
            const name = props.list.name;

            await api.removeListShare(props.list.id, user.value?.id ?? '');
            open.value = false;
            // die Ansicht räumt auf (`forgetLeftList`); danach gibt es dieses Sheet nicht mehr
            emit('left', name);
        }
    } catch (error) {
        logUnexpected(error);
        showToast(errorText(error), { icon: 'notgot' });
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <AppSheet v-model:open="open" :title="sheetTitle" :subtitle="sheetSubtitle">
        <p v-if="confirm?.type === 'remove'" class="share__confirm">
            {{ t('share.removeText', { name: confirm.share.name, list: list.name }) }}
        </p>

        <p v-else-if="confirm?.type === 'leave'" class="share__confirm">
            {{ t('share.leaveText', { list: list.name }) }}
        </p>

        <div v-else-if="isGuest" class="share">
            <p class="share__text">{{ t('share.guestText', { name: sharedByName }) }}</p>
            <p class="share__hint">{{ t('share.guestLimits') }}</p>
            <AppButton variant="danger" class="share__leave" @click="confirm = { type: 'leave' }">
                <LogOut aria-hidden="true" />
                {{ t('share.leave') }}
            </AppButton>
        </div>

        <div v-else class="share">
            <p class="share__text">{{ t('share.intro') }}</p>

            <div class="share__card">
                <template v-if="created">
                    <p class="share__link-title">
                        <Link2 class="share__link-icon" aria-hidden="true" />
                        {{ t('share.created') }}
                    </p>
                    <p class="share__link" translate="no">{{ link }}</p>
                    <p class="share__hint">{{ t('share.validUntil', { date: formatDate(created.expiresAt) }) }}</p>
                    <div class="share__actions">
                        <AppButton v-if="canShare" variant="secondary" @click="shareLink()">
                            <Share2 aria-hidden="true" />
                            {{ t('share.share') }}
                        </AppButton>
                        <AppButton variant="secondary" @click="copyLink()">
                            <Copy aria-hidden="true" />
                            {{ t('share.copy') }}
                        </AppButton>
                    </div>
                </template>
                <template v-else>
                    <p class="share__hint">{{ t('share.rules') }}</p>
                    <AppButton :disabled="pending" @click="createLink">
                        <component :is="canShare ? Share2 : Link2" aria-hidden="true" />
                        {{ canShare ? t('share.createAndShare') : t('share.createAndCopy') }}
                    </AppButton>
                </template>
            </div>

            <section class="share__section" :aria-label="t('share.participants')" :aria-busy="status === 'loading' ? 'true' : 'false'">
                <SectionLabel :label="t('share.participants')" :count="status === 'ready' ? shares.length : null" :level="3" />

                <p v-if="status === 'error'" class="share__error" role="alert">
                    <TriangleAlert aria-hidden="true" />
                    {{ t('share.loadError') }}
                    <button type="button" class="share__retry" @click="load">{{ t('common.retry') }}</button>
                </p>

                <ul v-else-if="status !== 'ready'" class="share__rows" aria-hidden="true">
                    <li v-for="index in Math.max(skeletonRows, 1)" :key="index" class="share__row share__row--skeleton">
                        <span class="share__skeleton-avatar"></span>
                        <span class="share__skeleton-text"></span>
                    </li>
                </ul>

                <template v-else>
                    <p v-if="shares.length === 0 && invites.length === 0" class="share__empty">{{ t('share.nobody') }}</p>

                    <ul v-if="shares.length > 0 || invites.length > 0" class="share__rows">
                        <li v-for="share in shares" :key="share.id" class="share__row">
                            <UserAvatar :user="share" />
                            <span class="share__row-text">
                                <span class="share__row-name">{{ share.name }}</span>
                                <span class="share__row-meta">
                                    {{ t('share.since', { date: formatDate(share.acceptedAt), name: share.invitedBy }) }}
                                </span>
                            </span>
                            <button
                                type="button"
                                class="share__row-action"
                                :aria-label="t('share.remove', { name: share.name })"
                                @click="confirm = { type: 'remove', share }"
                            >
                                <UserMinus aria-hidden="true" />
                            </button>
                        </li>
                        <li v-for="invite in invites" :key="invite.id" class="share__row">
                            <span class="share__invite-icon" aria-hidden="true"><Link2 /></span>
                            <span class="share__row-text">
                                <span class="share__row-name">{{ t('share.openLink') }}</span>
                                <span class="share__row-meta">
                                    {{ t('share.openLinkMeta', { name: invite.createdBy, date: formatDate(invite.expiresAt) }) }}
                                </span>
                            </span>
                            <button
                                type="button"
                                class="share__row-action"
                                :aria-label="t('share.withdraw')"
                                :disabled="pending"
                                @click="withdraw(invite)"
                            >
                                <Link2Off aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                </template>
            </section>
        </div>

        <template v-if="confirm" #footer>
            <AppButton variant="secondary" @click="confirm = null">{{ t('common.cancel') }}</AppButton>
            <AppButton variant="danger-solid" :disabled="pending" @click="confirmAction">
                {{ confirm.type === 'remove' ? t('share.removeConfirm') : t('share.leaveConfirm') }}
            </AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.share {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.share__text,
.share__confirm {
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.share__confirm {
    font-size: var(--font-size-name);
}

.share__hint,
.share__empty {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.share__empty {
    padding: 0 var(--space-2);
}

.share__leave {
    align-self: flex-start;
}

.share__card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.share__link-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.share__link-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-accent-text);
}

.share__link {
    align-self: stretch;
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    background: var(--color-surface-2);
    color: var(--color-text-1);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    overflow-wrap: anywhere;
    user-select: all;
}

.share__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.share__section {
    display: flex;
    flex-direction: column;
}

.share__rows {
    overflow: hidden;
    margin: 0;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    list-style: none;
}

.share__row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-2) var(--space-2) var(--space-4);
}

.share__row + .share__row {
    border-top: 1px solid var(--color-border);
}

.share__row--skeleton {
    padding-right: var(--space-4);
}

.share__row-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.share__row-name {
    overflow-wrap: anywhere;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.share__row-meta {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.share__invite-icon {
    display: grid;
    flex: none;
    place-items: center;
    width: var(--avatar-size);
    height: var(--avatar-size);
    border-radius: 50%;
    background: var(--color-surface-2);
    color: var(--color-text-2);
}

.share__invite-icon svg {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.share__row-action {
    display: grid;
    flex: none;
    place-items: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: var(--radius-pill);
    color: var(--color-text-2);
}

.share__row-action svg {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.share__row-action:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 0;
}

@media (hover: hover) {
    .share__row-action:hover {
        background: var(--color-surface-2);
        color: var(--color-text-1);
    }
}

.share__skeleton-avatar {
    flex: none;
    width: var(--avatar-size);
    height: var(--avatar-size);
    border-radius: 50%;
    background: var(--color-skeleton);
}

.share__skeleton-text {
    width: 40%;
    height: var(--font-size-name);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.share__error {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    color: var(--color-danger);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.share__error svg {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.share__retry {
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
    text-decoration: underline;
}
</style>
