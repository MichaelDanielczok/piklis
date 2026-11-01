<script setup>
/**
 * Sheet „Neue Vorlage“ bzw. „Vorlage verwalten“ (nicht entworfen, im Stil
 * von „Neue Liste / Liste bearbeiten“):
 *
 * - Neu: Name (Pflicht, höchstens 100 Zeichen) und mit Haushalt „Für wen?“
 *   (Haushalt voreingestellt, wie bei Listen). Die Produkte kommen danach
 *   über „Vorlage bearbeiten“ dazu.
 * - Verwalten: Name ändern, Zugehörigkeit (eine persönliche Vorlage in den
 *   Haushalt verschieben, mit Rückfrage im selben Sheet, oder kopieren) und
 *   „Vorlage löschen“ mit Rückfrage.
 *
 * Die Produkte selbst ändert ausdrücklich „Vorlage bearbeiten“ in der
 * Ansicht, nicht dieses Sheet.
 *
 * v-model:open · `template` null = neu · `pending` sperrt die Knöpfe
 * Emits: submit({ name, householdId }) · move · copy · delete
 */
import { computed, ref, useId, watch } from 'vue';
import { CircleAlert, Copy, House, Trash2, User } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import { useSession } from '@/composables/useSession.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 100;

const props = defineProps({
    template: {
        type: Object,
        default: null
    },
    pending: {
        type: Boolean,
        default: false
    },
    error: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['submit', 'move', 'copy', 'delete']);
const open = defineModel('open', { type: Boolean, default: false });

const ids = {
    form: useId(),
    name: useId(),
    nameError: useId(),
    owner: useId(),
    ownerHeading: useId()
};

const { household } = useSession();

const nameInput = ref(null);
const name = ref('');
const owner = ref('household');
const submitted = ref(false);
const confirmDelete = ref(false);
const confirmMove = ref(false);

const isNew = computed(() => props.template === null);
const isHouseholdTemplate = computed(() => Boolean(props.template?.householdId));
const canMoveToHousehold = computed(() => Boolean(household.value)
    && !isNew.value
    && !isHouseholdTemplate.value
    && props.template?.role === 'owner');

const title = computed(() => {
    if (confirmDelete.value) {
        return t('templateForm.deleteTitle');
    }

    if (confirmMove.value) {
        return t('templateForm.moveTitle');
    }

    return isNew.value ? t('templateForm.newTitle') : t('templateForm.manageTitle');
});

const nameError = computed(() => {
    if (!submitted.value) {
        return '';
    }

    const trimmed = name.value.trim();

    if (trimmed === '') {
        return t('templateForm.nameRequired');
    }

    return trimmed.length > NAME_MAX ? t('listForm.nameTooLong', { count: NAME_MAX }) : '';
});

watch(open, (isOpen) => {
    if (isOpen) {
        name.value = props.template?.name ?? '';
        owner.value = household.value ? 'household' : 'personal';
        submitted.value = false;
        confirmDelete.value = false;
        confirmMove.value = false;
    }
});

function focusName() {
    if (isNew.value) {
        nameInput.value?.focus();
    }
}

function submit() {
    submitted.value = true;

    if (nameError.value) {
        nameInput.value?.focus();
        return;
    }

    const householdId = isNew.value && household.value && owner.value === 'household' ? household.value.id : null;

    emit('submit', { name: name.value.trim(), householdId });
}
</script>

<template>
    <AppSheet v-model:open="open" :title="title" @presented="focusName">
        <div v-if="confirmDelete" class="template-form__stack">
            <p class="template-form__confirm">
                {{ t('templateForm.deleteText', { name: template?.name ?? '' }) }}
            </p>
            <p class="template-form__hint">
                {{ isHouseholdTemplate ? t('templateForm.deleteHouseholdHint') : t('templateForm.deleteHint') }}
            </p>
        </div>

        <div v-else-if="confirmMove" class="template-form__stack">
            <p class="template-form__confirm">
                {{ t('templateForm.moveText', { name: template?.name ?? '', household: household?.name ?? '' }) }}
            </p>
            <p class="template-form__hint">{{ t('templateForm.moveHint') }}</p>
        </div>

        <form v-else :id="ids.form" class="template-form" novalidate @submit.prevent="submit">
            <label class="form-field" :for="ids.name">
                <span class="form-field__label">{{ t('templateForm.name') }}</span>
                <input
                    :id="ids.name"
                    ref="nameInput"
                    v-model="name"
                    class="form-field__input"
                    type="text"
                    :maxlength="NAME_MAX"
                    :placeholder="t('templateForm.namePlaceholder')"
                    autocomplete="off"
                    enterkeyhint="done"
                    :aria-invalid="nameError ? 'true' : 'false'"
                    :aria-describedby="nameError ? ids.nameError : undefined"
                >
                <span v-if="nameError" :id="ids.nameError" class="form-field__error">
                    <CircleAlert aria-hidden="true" />
                    {{ nameError }}
                </span>
            </label>

            <fieldset v-if="isNew && household" class="choice-list">
                <legend class="form-field__label template-form__legend">{{ t('listForm.owner') }}</legend>
                <label class="choice">
                    <input v-model="owner" class="choice__radio" type="radio" :name="ids.owner" value="household">
                    <House class="template-form__owner-icon" aria-hidden="true" />
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.ownerHousehold', { name: household.name }) }}</span>
                        <span class="choice__hint">{{ t('templateForm.ownerHouseholdHint') }}</span>
                    </span>
                </label>
                <label class="choice">
                    <input v-model="owner" class="choice__radio" type="radio" :name="ids.owner" value="personal">
                    <User class="template-form__owner-icon" aria-hidden="true" />
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.ownerPersonal') }}</span>
                        <span class="choice__hint">{{ t('listForm.ownerPersonalHint') }}</span>
                    </span>
                </label>
            </fieldset>

            <p v-if="isNew" class="template-form__hint">{{ t('templateForm.newHint') }}</p>

            <section v-if="!isNew" class="template-form__owner" :aria-labelledby="ids.ownerHeading">
                <h3 :id="ids.ownerHeading" class="form-field__label">{{ t('listForm.owner') }}</h3>
                <p class="template-form__owner-text">
                    <component :is="isHouseholdTemplate ? House : User" class="template-form__owner-icon" aria-hidden="true" />
                    <span>
                        {{ isHouseholdTemplate
                            ? t('listForm.ownedByHousehold', { name: household?.name ?? t('lists.originHousehold') })
                            : t('listForm.ownedByMe') }}
                    </span>
                </p>
                <div v-if="canMoveToHousehold" class="template-form__owner-actions">
                    <AppButton variant="secondary" :disabled="pending" @click="confirmMove = true">
                        <House aria-hidden="true" />
                        {{ t('listForm.move') }}
                    </AppButton>
                    <AppButton variant="secondary" :disabled="pending" @click="emit('copy')">
                        <Copy aria-hidden="true" />
                        {{ t('listForm.copy') }}
                    </AppButton>
                    <p class="template-form__hint">{{ t('templateForm.moveCopyHint') }}</p>
                </div>
            </section>

            <AppButton
                v-if="!isNew"
                variant="danger"
                class="template-form__delete"
                @click="confirmDelete = true"
            >
                <Trash2 aria-hidden="true" />
                {{ t('templateForm.delete') }}
            </AppButton>
        </form>

        <p v-if="error" class="form-field__error template-form__error" role="alert">
            <CircleAlert aria-hidden="true" />
            {{ error }}
        </p>

        <template #footer>
            <template v-if="confirmDelete">
                <AppButton variant="secondary" @click="confirmDelete = false">{{ t('common.cancel') }}</AppButton>
                <AppButton variant="danger-solid" :disabled="pending" @click="emit('delete')">{{ t('listForm.deleteConfirm') }}</AppButton>
            </template>
            <template v-else-if="confirmMove">
                <AppButton variant="secondary" @click="confirmMove = false">{{ t('common.cancel') }}</AppButton>
                <AppButton :disabled="pending" @click="emit('move')">{{ t('listForm.moveConfirm') }}</AppButton>
            </template>
            <template v-else>
                <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
                <AppButton type="submit" :form="ids.form" :disabled="pending">
                    {{ isNew ? t('listForm.create') : t('common.save') }}
                </AppButton>
            </template>
        </template>
    </AppSheet>
</template>

<style scoped>
.template-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.template-form__stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.template-form__confirm {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.template-form__legend {
    margin-bottom: var(--space-2);
    padding: 0;
}

.template-form__owner {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.template-form__owner-text {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.template-form__owner-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-text-2);
}

.template-form__owner-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    padding-top: var(--space-1);
}

.template-form__delete {
    align-self: flex-start;
}

.template-form__error {
    margin-top: var(--space-3);
}

.template-form__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
