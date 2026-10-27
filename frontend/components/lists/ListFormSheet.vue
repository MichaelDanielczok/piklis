<script setup>
/**
 * Sheet „Neue Liste“ bzw. „Liste bearbeiten“: frei gewählter Name (Pflicht,
 * höchstens 100 Zeichen) und ein freiwilliges Datum (Ohne Datum · Heute ·
 * Morgen · anderes Datum). Beim Bearbeiten darunter „Liste löschen“ mit
 * Rückfrage im selben Sheet.
 *
 * v-model:open · `list` null = neu
 * Emits: submit({ name, shopDate }) · delete
 */
import { computed, ref, useId, watch } from 'vue';
import { CircleAlert, Trash2 } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import { shopDateFromToday } from '@/utils/shopDate.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 100;

const props = defineProps({
    list: {
        type: Object,
        default: null
    },
    entryCount: {
        type: Number,
        default: 0
    }
});

const emit = defineEmits(['submit', 'delete']);
const open = defineModel('open', { type: Boolean, default: false });

const ids = {
    name: useId(),
    nameError: useId(),
    date: useId(),
    dateLabel: useId()
};

const nameInput = ref(null);
const name = ref('');
const shopDate = ref(null);
const submitted = ref(false);
const confirmDelete = ref(false);

const isNew = computed(() => props.list === null);
const today = computed(() => shopDateFromToday(0));
const tomorrow = computed(() => shopDateFromToday(1));

const dateChoice = computed(() => {
    if (!shopDate.value) {
        return 'none';
    }

    if (shopDate.value === today.value) {
        return 'today';
    }

    return shopDate.value === tomorrow.value ? 'tomorrow' : 'other';
});

const nameError = computed(() => {
    if (!submitted.value) {
        return '';
    }

    const trimmed = name.value.trim();

    if (trimmed === '') {
        return t('listForm.nameRequired');
    }

    return trimmed.length > NAME_MAX ? t('listForm.nameTooLong', { count: NAME_MAX }) : '';
});

watch(open, (isOpen) => {
    if (isOpen) {
        name.value = props.list?.name ?? '';
        shopDate.value = props.list?.shopDate ?? null;
        submitted.value = false;
        confirmDelete.value = false;
    }
});

function focusName() {
    if (isNew.value) {
        nameInput.value?.focus();
    }
}

function choose(value) {
    shopDate.value = value;
}

function onDateInput(event) {
    shopDate.value = event.target.value || null;
}

function submit() {
    submitted.value = true;

    if (nameError.value) {
        nameInput.value?.focus();
        return;
    }

    emit('submit', { name: name.value.trim(), shopDate: shopDate.value });
    open.value = false;
}

function remove() {
    emit('delete');
    open.value = false;
}
</script>

<template>
    <AppSheet
        v-model:open="open"
        :title="confirmDelete ? t('listForm.deleteTitle') : (isNew ? t('listForm.newTitle') : t('listForm.editTitle'))"
        @presented="focusName"
    >
        <p v-if="confirmDelete" class="list-form__confirm">
            {{ t('listForm.deleteText', { name: list?.name ?? '', count: entryCount }) }}
        </p>

        <form v-else :id="ids.name + '-form'" class="list-form" novalidate @submit.prevent="submit">
            <label class="form-field" :for="ids.name">
                <span class="form-field__label">{{ t('listForm.name') }}</span>
                <input
                    :id="ids.name"
                    ref="nameInput"
                    v-model="name"
                    class="form-field__input"
                    type="text"
                    :maxlength="NAME_MAX"
                    :placeholder="t('listForm.namePlaceholder')"
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

            <fieldset class="list-form__dates">
                <legend class="form-field__label">
                    {{ t('listForm.date') }} <span class="form-field__label-hint">· {{ t('listForm.dateHint') }}</span>
                </legend>
                <div class="list-form__chips">
                    <button
                        type="button"
                        class="list-form__chip"
                        :aria-pressed="dateChoice === 'none' ? 'true' : 'false'"
                        @click="choose(null)"
                    >
                        {{ t('listForm.noDate') }}
                    </button>
                    <button
                        type="button"
                        class="list-form__chip"
                        :aria-pressed="dateChoice === 'today' ? 'true' : 'false'"
                        @click="choose(today)"
                    >
                        {{ t('listForm.today') }}
                    </button>
                    <button
                        type="button"
                        class="list-form__chip"
                        :aria-pressed="dateChoice === 'tomorrow' ? 'true' : 'false'"
                        @click="choose(tomorrow)"
                    >
                        {{ t('listForm.tomorrow') }}
                    </button>
                </div>
                <label class="form-field list-form__other" :for="ids.date">
                    <span class="visually-hidden">{{ t('listForm.dateChoice') }}</span>
                    <input
                        :id="ids.date"
                        class="form-field__input list-form__date"
                        :class="{ 'list-form__date--chosen': dateChoice === 'other' }"
                        type="date"
                        :value="shopDate ?? ''"
                        :aria-label="t('listForm.otherDate')"
                        @change="onDateInput"
                    >
                </label>
            </fieldset>

            <AppButton
                v-if="!isNew"
                variant="danger"
                class="list-form__delete"
                @click="confirmDelete = true"
            >
                <Trash2 aria-hidden="true" />
                {{ t('listForm.delete') }}
            </AppButton>
        </form>

        <template #footer>
            <template v-if="confirmDelete">
                <AppButton variant="secondary" @click="confirmDelete = false">{{ t('common.cancel') }}</AppButton>
                <AppButton variant="danger-solid" @click="remove">{{ t('listForm.deleteConfirm') }}</AppButton>
            </template>
            <template v-else>
                <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
                <AppButton type="submit" :form="ids.name + '-form'">
                    {{ isNew ? t('listForm.create') : t('common.save') }}
                </AppButton>
            </template>
        </template>
    </AppSheet>
</template>

<style scoped>
.list-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.list-form__confirm {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.list-form__dates {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.list-form__dates legend {
    padding: 0;
    margin-bottom: var(--space-2);
}

.list-form__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.list-form__chip {
    min-height: var(--tap-size);
    padding: 0 var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-pill);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
    transition: background-color var(--duration-fast) var(--ease-out);
}

.list-form__chip[aria-pressed="true"] {
    border-color: var(--color-accent);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-weight: var(--font-weight-semibold);
}

@media (hover: hover) {
    .list-form__chip[aria-pressed="false"]:hover {
        background: var(--color-surface-2);
    }
}

.list-form__date {
    min-width: 0;
}

.list-form__date--chosen {
    border-color: var(--color-accent);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}

.list-form__delete {
    align-self: flex-start;
}
</style>
