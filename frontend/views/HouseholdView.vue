<script setup>
/**
 * Haushalt (aus dem Benutzermenü), im Stil der Listenansichten.
 *
 * Ohne Haushalt: „Haushalt anlegen“ und „Mit Code beitreten“.
 * Mit Haushalt: Kopf mit Name und eigener Rolle, Mitglieder (Administratoren
 * ändern Rollen und entfernen über „…“), Einladungscode (nur
 * Administratoren: kopieren, teilen, neu erzeugen), Hinweis zu den Listen
 * und unten Wechseln, Verlassen, Auflösen (Sheet mit Kopie-Angebot).
 *
 * Ladezustand in Endhöhe: Name und Rolle kennt die App schon aus `GET /me`;
 * die Mitglieder zeigen so viele Platzhalterzeilen wie zuletzt auf diesem
 * Gerät (`pk.householdMembers.<user>`).
 */
import { computed, ref, useId, watch } from 'vue';
import { onIonViewWillEnter } from '@ionic/vue';
import { Copy, Ellipsis, House, LogOut, RefreshCw, Share2, Trash2, TriangleAlert, Users } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import UserAvatar from '@/components/ui/UserAvatar.vue';
import MemberSheet from '@/components/household/MemberSheet.vue';
import HouseholdLeaveSheet from '@/components/household/HouseholdLeaveSheet.vue';
import { ApiError } from '@/api/index.js';
import {
    createHousehold,
    joinHousehold,
    loadHousehold,
    renewInvite,
    useHousehold
} from '@/composables/useHousehold.js';
import { useSession } from '@/composables/useSession.js';
import { showToast } from '@/composables/useToast.js';
import { formatDate, t } from '@/i18n/index.js';

const MEMBERS_KEY = 'pk.householdMembers.';
const MAX_SKELETONS = 8;

const { household, status } = useHousehold();
const { user, household: summary } = useSession();

const ids = {
    name: useId(),
    nameError: useId(),
    code: useId(),
    codeError: useId()
};

const name = ref('');
const code = ref('');
const nameError = ref('');
const codeError = ref('');
const pending = ref(false);
const memberSheet = ref(null);
const memberSheetOpen = ref(false);
const leaveMode = ref('leave');
const leaveOpen = ref(false);

function readMemberCount() {
    try {
        const value = Number.parseInt(localStorage.getItem(MEMBERS_KEY + (user.value?.id ?? '')) ?? '', 10);

        return Number.isFinite(value) && value > 0 ? Math.min(value, MAX_SKELETONS) : 2;
    } catch {
        return 2;
    }
}

const skeletonMembers = ref(readMemberCount());

watch(
    () => household.value?.members?.length ?? null,
    (count) => {
        if (!count) {
            return;
        }

        try {
            localStorage.setItem(MEMBERS_KEY + (user.value?.id ?? ''), String(count));
        } catch {
            // nur Bequemlichkeit für den Ladezustand
        }
    }
);

/** Angezeigter Haushalt: geladen, sonst die Kurzform aus der Session. */
const current = computed(() => (household.value === undefined ? summary.value : household.value));
const loaded = computed(() => household.value !== undefined);
const isAdmin = computed(() => current.value?.role === 'admin');
const members = computed(() => household.value?.members ?? []);

const summaryText = computed(() => {
    if (!loaded.value || !household.value) {
        return t(isAdmin.value ? 'household.roleAdmin' : 'household.roleMember');
    }

    return t(isAdmin.value ? 'household.summaryAdmin' : 'household.summaryMember', { count: members.value.length });
});

/** Code zum Lesen in zwei Vierergruppen: „ABCD-EFGH“. */
const formattedCode = computed(() => {
    const value = household.value?.invite?.code ?? '';

    return value.length === 8 ? value.slice(0, 4) + '-' + value.slice(4) : value;
});

const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

function errorText(error) {
    if (error instanceof ApiError && error.status === 0) {
        return t('household.errors.network');
    }

    const key = 'household.errors.' + (error?.code ?? '');
    const text = t(key);

    return text === key ? t('common.error') : text;
}

function reload() {
    loadHousehold().catch((error) => console.error(error));
}

onIonViewWillEnter(reload);

async function create() {
    nameError.value = name.value.trim() === '' ? t('household.nameRequired') : '';

    if (nameError.value || pending.value) {
        return;
    }

    pending.value = true;

    try {
        const result = await createHousehold(name.value.trim());

        name.value = '';
        showToast(t('household.created', { name: result?.name ?? '' }), { icon: 'info' });
    } catch (error) {
        console.error(error);
        nameError.value = errorText(error);
    } finally {
        pending.value = false;
    }
}

async function join() {
    codeError.value = code.value.trim() === '' ? t('household.codeRequired') : '';

    if (codeError.value || pending.value) {
        return;
    }

    pending.value = true;

    try {
        const result = await joinHousehold(code.value);

        code.value = '';
        showToast(t('household.joined', { name: result?.name ?? '' }), { icon: 'info' });
    } catch (error) {
        console.error(error);
        codeError.value = errorText(error);
    } finally {
        pending.value = false;
    }
}

async function renew() {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        await renewInvite();
        showToast(t('household.inviteRenewed'), { icon: 'info' });
    } catch (error) {
        console.error(error);
        showToast(errorText(error), { icon: 'notgot' });
    } finally {
        pending.value = false;
    }
}

async function copyCode() {
    try {
        await navigator.clipboard.writeText(formattedCode.value);
        showToast(t('household.copied'), { icon: 'info' });
    } catch (error) {
        console.error(error);
        showToast(t('common.error'), { icon: 'notgot' });
    }
}

async function shareCode() {
    try {
        await navigator.share({
            text: t('household.inviteShareText', { name: household.value?.name ?? '', code: formattedCode.value })
        });
    } catch (error) {
        // Abbrechen im Teilen-Menü ist kein Fehler
        if (error?.name !== 'AbortError') {
            console.error(error);
            copyCode();
        }
    }
}

function openMember(member) {
    memberSheet.value = member;
    memberSheetOpen.value = true;
}

function openLeave(mode) {
    leaveMode.value = mode;
    leaveOpen.value = true;
}
</script>

<template>
    <AppPage :title="t('household.title')" back width="narrow">
        <div class="household">
            <!-- ohne Haushalt: anlegen oder beitreten -->
            <template v-if="!current">
                <p class="household__intro">{{ t('household.intro') }}</p>

                <section class="household__card" :aria-labelledby="ids.name + '-title'">
                    <h2 :id="ids.name + '-title'" class="household__title">{{ t('household.create') }}</h2>
                    <p class="household__text">{{ t('household.createText') }}</p>
                    <form class="household__form" novalidate @submit.prevent="create">
                        <label class="form-field" :for="ids.name">
                            <span class="form-field__label">{{ t('household.name') }}</span>
                            <input
                                :id="ids.name"
                                v-model="name"
                                class="form-field__input"
                                type="text"
                                maxlength="100"
                                autocomplete="off"
                                enterkeyhint="done"
                                :placeholder="t('household.namePlaceholder')"
                                :aria-invalid="nameError ? 'true' : 'false'"
                                :aria-describedby="nameError ? ids.nameError : undefined"
                            >
                            <span v-if="nameError" :id="ids.nameError" class="form-field__error" role="alert">
                                <TriangleAlert aria-hidden="true" />
                                {{ nameError }}
                            </span>
                        </label>
                        <AppButton type="submit" :disabled="pending">
                            <House aria-hidden="true" />
                            {{ t('household.create') }}
                        </AppButton>
                    </form>
                </section>

                <section class="household__card" :aria-labelledby="ids.code + '-title'">
                    <h2 :id="ids.code + '-title'" class="household__title">{{ t('household.join') }}</h2>
                    <p class="household__text">{{ t('household.joinText') }}</p>
                    <form class="household__form" novalidate @submit.prevent="join">
                        <label class="form-field" :for="ids.code">
                            <span class="form-field__label">{{ t('household.code') }}</span>
                            <input
                                :id="ids.code"
                                v-model="code"
                                class="form-field__input household__code-input"
                                type="text"
                                maxlength="12"
                                autocomplete="off"
                                autocapitalize="characters"
                                spellcheck="false"
                                enterkeyhint="go"
                                placeholder="ABCD-EFGH"
                                :aria-invalid="codeError ? 'true' : 'false'"
                                :aria-describedby="codeError ? ids.codeError : undefined"
                            >
                            <span v-if="codeError" :id="ids.codeError" class="form-field__error" role="alert">
                                <TriangleAlert aria-hidden="true" />
                                {{ codeError }}
                            </span>
                        </label>
                        <AppButton type="submit" variant="secondary" :disabled="pending">
                            {{ t('household.joinSubmit') }}
                        </AppButton>
                    </form>
                </section>
            </template>

            <template v-else>
                <section class="household__head">
                    <span class="household__icon" aria-hidden="true"><House /></span>
                    <div class="household__head-text">
                        <h2 class="household__name">{{ current.name }}</h2>
                        <p class="household__summary">{{ summaryText }}</p>
                    </div>
                </section>

                <section class="household__section" :aria-label="t('household.members')">
                    <SectionLabel :label="t('household.members')" :count="loaded ? members.length : null" />

                    <EmptyState
                        v-if="status === 'error' && !loaded"
                        :icon="TriangleAlert"
                        :title="t('household.loadError')"
                    >
                        <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
                    </EmptyState>

                    <ul v-else-if="!loaded" class="household__members" aria-busy="true">
                        <li v-for="index in skeletonMembers" :key="index" class="household__member household__member--skeleton">
                            <span class="household__skeleton-avatar"></span>
                            <span class="household__skeleton-text"></span>
                        </li>
                    </ul>

                    <ul v-else class="household__members">
                        <li v-for="member in members" :key="member.id" class="household__member">
                            <UserAvatar :user="member" />
                            <span class="household__member-name">
                                {{ member.name }}
                                <span v-if="member.id === user?.id" class="household__you">({{ t('household.you') }})</span>
                            </span>
                            <span v-if="member.role === 'admin'" class="household__role">{{ t('household.roleAdmin') }}</span>
                            <button
                                v-if="isAdmin && member.id !== user?.id"
                                type="button"
                                class="household__more"
                                :aria-label="t('household.memberActions', { name: member.name })"
                                @click="openMember(member)"
                            >
                                <Ellipsis aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="household__section" :aria-label="t('household.invite')">
                    <SectionLabel :label="t('household.invite')" />
                    <div v-if="isAdmin" class="household__card">
                        <template v-if="!loaded || household?.invite">
                            <p class="household__code" :class="{ 'household__code--loading': !loaded }">
                                <template v-if="loaded">
                                    <span aria-hidden="true">{{ formattedCode }}</span>
                                    <span class="visually-hidden">{{ household.invite.code.split('').join(' ') }}</span>
                                </template>
                            </p>
                            <p class="household__text">
                                {{ loaded ? t('household.inviteText', { date: formatDate(household.invite.expiresAt) }) : ' ' }}
                            </p>
                        </template>
                        <p v-else class="household__text">{{ t('household.inviteNone') }}</p>
                        <div class="household__actions">
                            <AppButton v-if="canShare" variant="secondary" :disabled="!household?.invite" @click="shareCode">
                                <Share2 aria-hidden="true" />
                                {{ t('household.inviteShare') }}
                            </AppButton>
                            <AppButton variant="secondary" :disabled="!household?.invite" @click="copyCode">
                                <Copy aria-hidden="true" />
                                {{ t('household.inviteCopy') }}
                            </AppButton>
                            <AppButton variant="secondary" :disabled="pending || !loaded" @click="renew">
                                <RefreshCw aria-hidden="true" />
                                {{ t('household.inviteNew') }}
                            </AppButton>
                        </div>
                    </div>
                    <p v-else class="household__note">{{ t('household.inviteOnlyAdmins') }}</p>
                </section>

                <section class="household__section" :aria-label="t('household.lists')">
                    <SectionLabel :label="t('household.lists')" />
                    <p class="household__note">{{ t('household.listsText') }}</p>
                </section>

                <section class="household__section" :aria-label="t('household.manage')">
                    <SectionLabel :label="t('household.manage')" />
                    <div class="household__manage">
                        <AppButton variant="secondary" :disabled="!loaded" @click="openLeave('switch')">
                            <Users aria-hidden="true" />
                            {{ t('household.switch') }}
                        </AppButton>
                        <AppButton variant="danger" :disabled="!loaded" @click="openLeave('leave')">
                            <LogOut aria-hidden="true" />
                            {{ t('household.leave') }}
                        </AppButton>
                        <AppButton v-if="isAdmin" variant="danger" :disabled="!loaded" @click="openLeave('dissolve')">
                            <Trash2 aria-hidden="true" />
                            {{ t('household.dissolve') }}
                        </AppButton>
                    </div>
                </section>
            </template>
        </div>

        <MemberSheet v-model:open="memberSheetOpen" :member="memberSheet" />
        <HouseholdLeaveSheet v-if="household" v-model:open="leaveOpen" :mode="leaveMode" :household="household" />
    </AppPage>
</template>

<style scoped>
.household {
    display: flex;
    flex-direction: column;
    gap: var(--space-6);
    padding: var(--space-2) var(--page-gutter) var(--space-8);
}

.household__intro,
.household__note {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.household__card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.household__title {
    color: var(--color-text-1);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}

.household__text {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.household__form {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-4);
    padding-top: var(--space-1);
}

.household__form > .form-field {
    align-self: stretch;
}

.household__code-input {
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.household__head {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.household__icon {
    display: grid;
    flex: none;
    place-items: center;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-md);
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
}

.household__icon svg {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.household__head-text {
    min-width: 0;
}

.household__name {
    overflow-wrap: anywhere;
    color: var(--color-text-1);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}

.household__summary {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.household__section {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.household__members {
    overflow: hidden;
    margin: 0;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    list-style: none;
}

.household__member {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-2) var(--space-2) var(--space-4);
}

.household__member + .household__member {
    border-top: 1px solid var(--color-border);
}

.household__member--skeleton {
    padding-right: var(--space-4);
}

.household__member-name {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.household__you {
    color: var(--color-text-2);
    font-weight: var(--font-weight-regular);
}

.household__role {
    flex: none;
    padding: var(--space-1) var(--space-3);
    border-radius: var(--radius-pill);
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-note);
}

.household__more {
    display: grid;
    flex: none;
    place-items: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: var(--radius-pill);
    color: var(--color-text-2);
}

.household__more svg {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.household__more:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 0;
}

@media (hover: hover) {
    .household__more:hover {
        background: var(--color-surface-2);
    }
}

.household__skeleton-avatar {
    flex: none;
    width: var(--avatar-size);
    height: var(--avatar-size);
    border-radius: 50%;
    background: var(--color-skeleton);
}

.household__skeleton-text {
    width: 40%;
    height: var(--font-size-name);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.household__code {
    min-height: var(--line-height-h1);
    color: var(--color-text-1);
    font-size: var(--font-size-h1);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    letter-spacing: 0.08em;
    line-height: var(--line-height-h1);
}

.household__code--loading {
    width: 11rem;
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.household__actions,
.household__manage {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.household__manage {
    flex-direction: column;
    align-items: stretch;
}

@media (min-width: 45em) {
    .household__manage {
        flex-direction: row;
        align-items: flex-start;
    }
}
</style>
