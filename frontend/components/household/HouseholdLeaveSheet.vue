<script setup>
/**
 * Sheet zum Verlassen, Wechseln oder Auflösen des Haushalts (Entscheidungen
 * „Wechsel“ und „Letztes Mitglied geht / Auflösen“).
 *
 * - Vorher bietet es an, einzelne Haushaltslisten als persönliche Kopie
 *   mitzunehmen (nichts vorgewählt); die Kopien entstehen vor dem Gehen.
 * - `leave`: Die Listen bleiben im Haushalt. Als letztes Mitglied werden
 *   Haushalt und Listen gelöscht (Hinweis, Knopf „Verlassen und löschen“).
 * - `switch`: zusätzlich der Code des neuen Haushalts.
 * - `dissolve` (Administratoren): Haushalt und Listen werden gelöscht.
 * - Der einzige Administrator neben anderen Mitgliedern kann nicht gehen:
 *   nur der Hinweis, erst jemanden zum Administrator zu machen.
 *
 * v-model:open · `mode` leave | switch | dissolve · `household` (geladen)
 */
import { computed, ref, useId, watch } from 'vue';
import { TriangleAlert } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import { ApiError } from '@/api/index.js';
import { dissolveHousehold, joinHousehold, leaveHousehold, leaveSituation } from '@/composables/useHousehold.js';
import { copyListTo, loadLists, useLists } from '@/composables/useLists.js';
import { showToast } from '@/composables/useToast.js';
import { formatShopDate } from '@/utils/shopDate.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    mode: {
        type: String,
        default: 'leave',
        validator: (value) => ['leave', 'switch', 'dissolve'].includes(value)
    },
    household: {
        type: Object,
        required: true
    }
});

const open = defineModel('open', { type: Boolean, default: false });

const ids = {
    code: useId(),
    codeError: useId(),
    copyLabel: useId()
};

const { activeLists, archivedLists } = useLists();

const selected = ref([]);
const code = ref('');
const error = ref('');
const pending = ref(false);

// schon kopierte Listen: ein zweiter Versuch (z. B. falscher Code) kopiert nicht erneut
const copied = new Set();

const situation = computed(() => leaveSituation(props.household));
const blocked = computed(() => props.mode !== 'dissolve' && situation.value.blocked);
const deletes = computed(() => props.mode === 'dissolve' || situation.value.lastMember);

/** Listen des Haushalts, aktive zuerst. */
const lists = computed(() => [...activeLists.value, ...archivedLists.value]
    .filter((list) => list.householdId === props.household.id));

const title = computed(() => {
    if (props.mode === 'dissolve') {
        return t('household.dissolveTitle');
    }

    return props.mode === 'switch' ? t('household.switchTitle') : t('household.leaveTitle');
});

const text = computed(() => {
    const name = props.household.name;

    if (props.mode === 'dissolve') {
        return t('household.dissolveText', { name, count: lists.value.length });
    }

    if (situation.value.lastMember) {
        return t('household.lastMemberText', { name, count: lists.value.length });
    }

    return props.mode === 'switch' ? t('household.switchText', { name }) : t('household.leaveText', { name });
});

const confirmLabel = computed(() => {
    if (props.mode === 'dissolve') {
        return t('household.dissolveConfirm');
    }

    if (props.mode === 'switch') {
        return t('household.switchConfirm');
    }

    return deletes.value ? t('household.leaveDeleteConfirm') : t('household.leaveConfirm');
});

watch(open, (isOpen) => {
    if (isOpen) {
        selected.value = [];
        code.value = '';
        error.value = '';
        copied.clear();
        loadLists().catch((loadError) => console.error(loadError));
    }
});

function listHint(list) {
    const parts = [t('household.copyCount', { count: list.counts?.total ?? 0 })];
    const date = formatShopDate(list.shopDate);

    if (date) {
        parts.push(date);
    }

    if (list.status === 'completed') {
        parts.push(t('household.archived'));
    }

    return parts.join(' · ');
}

function errorText(failure) {
    if (failure instanceof ApiError && failure.status === 0) {
        return t('household.errors.network');
    }

    const key = 'household.errors.' + (failure?.code ?? '');
    const message = t(key);

    return message === key ? t('common.error') : message;
}

async function confirm() {
    if (pending.value) {
        return;
    }

    if (props.mode === 'switch' && code.value.trim() === '') {
        error.value = t('household.codeRequired');
        return;
    }

    const name = props.household.name;

    pending.value = true;
    error.value = '';

    try {
        for (const listId of selected.value) {
            if (!copied.has(listId)) {
                await copyListTo(listId, 'personal');
                copied.add(listId);
            }
        }

        let toast;

        if (props.mode === 'dissolve') {
            await dissolveHousehold();
            toast = t('household.dissolved', { name });
        } else if (props.mode === 'switch') {
            const joined = await joinHousehold(code.value, {
                switchHousehold: true,
                confirmDissolve: situation.value.lastMember
            });
            toast = t('household.joined', { name: joined?.name ?? '' });
        } else {
            await leaveHousehold({ confirmDissolve: situation.value.lastMember });
            toast = t('household.left', { name });
        }

        if (copied.size > 0) {
            toast += ' · ' + t('household.copiedLists', { count: copied.size });
        }

        open.value = false;
        showToast(toast, { icon: 'info' });
    } catch (failure) {
        console.error(failure);
        error.value = errorText(failure);
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <AppSheet v-model:open="open" :title="title">
        <div class="leave">
            <p v-if="blocked" class="leave__text">{{ t('household.blockedText') }}</p>

            <template v-else>
                <p class="leave__text">{{ text }}</p>

                <label v-if="mode === 'switch'" class="form-field" :for="ids.code">
                    <span class="form-field__label">{{ t('household.code') }}</span>
                    <input
                        :id="ids.code"
                        v-model="code"
                        class="form-field__input leave__code"
                        type="text"
                        maxlength="12"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        placeholder="ABCD-EFGH"
                        :aria-invalid="error ? 'true' : 'false'"
                        :aria-describedby="error ? ids.codeError : undefined"
                    >
                </label>

                <fieldset class="leave__copies">
                    <legend class="form-field__label">{{ t('household.copyOffer') }}</legend>
                    <p class="leave__hint">{{ t('household.copyOfferHint') }}</p>
                    <p v-if="lists.length === 0" class="leave__hint">{{ t('household.noLists') }}</p>
                    <label v-for="list in lists" :key="list.id" class="choice">
                        <input v-model="selected" class="leave__check" type="checkbox" :value="list.id">
                        <span class="choice__text">
                            <span class="choice__title">{{ list.name }}</span>
                            <span class="choice__hint">{{ listHint(list) }}</span>
                        </span>
                    </label>
                </fieldset>
            </template>

            <p v-if="error" :id="ids.codeError" class="form-field__error" role="alert">
                <TriangleAlert aria-hidden="true" />
                {{ error }}
            </p>
        </div>

        <template #footer>
            <template v-if="blocked">
                <AppButton variant="secondary" @click="open = false">{{ t('common.close') }}</AppButton>
            </template>
            <template v-else>
                <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
                <AppButton :variant="deletes ? 'danger-solid' : 'primary'" :disabled="pending" @click="confirm">
                    {{ confirmLabel }}
                </AppButton>
            </template>
        </template>
    </AppSheet>
</template>

<style scoped>
.leave {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.leave__text {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.leave__code {
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.leave__copies {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.leave__copies legend {
    padding: 0;
}

.leave__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

/* Kästchen statt Radiopunkt: mehrere Listen wählbar */
.leave__check {
    display: grid;
    flex: none;
    place-content: center;
    width: var(--space-6);
    height: var(--space-6);
    margin: 0;
    border: 2px solid var(--color-ring);
    border-radius: var(--radius-sm);
    background: var(--color-surface-1);
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
}

.leave__check:checked {
    border-color: var(--color-accent);
    background: var(--color-accent);
}

.leave__check:checked::after {
    content: "";
    width: 0.375rem;
    height: 0.75rem;
    margin-top: -0.125rem;
    border: solid var(--color-on-accent);
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.leave__check:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 2px;
}

.choice:has(.leave__check:checked) {
    border-color: var(--color-accent);
    background: var(--color-accent-tint);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}
</style>
