<script setup>
/**
 * Nicht übertragene Änderungen an einer Liste, auf die man keinen Zugriff
 * mehr hat (Freigabe entzogen, Haushalt verlassen) oder die gelöscht wurde
 * (Entscheidung „Freigabe entzogen“): Hinweis mit Zahl der Änderungen und
 * den Knöpfen „Als eigene Liste speichern“ und „Änderungen verwerfen“, voll
 * breit untereinander. Steht in der Übersicht und in der Liste selbst.
 *
 * `orphan`: aus `orphanView()` ({ name, reason, count, entries }).
 */
import { computed, useId } from 'vue';
import { CloudAlert, ListPlus, Trash2 } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import { t } from '@/i18n/index.js';

const props = defineProps({
    orphan: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['save', 'discard']);

const titleId = useId();

const text = computed(() => (props.orphan.reason === 'list_deleted'
    ? t('orphan.deleted', { count: props.orphan.count })
    : t('orphan.noAccess', { count: props.orphan.count })));
</script>

<template>
    <section class="orphan" :aria-labelledby="titleId">
        <p :id="titleId" class="orphan__title">
            <CloudAlert class="orphan__icon" aria-hidden="true" />
            {{ t('orphan.title', { name: orphan.name }) }}
        </p>
        <p class="orphan__text">{{ text }} {{ t('orphan.hint') }}</p>
        <div class="orphan__actions">
            <AppButton block @click="emit('save')">
                <ListPlus aria-hidden="true" />
                {{ t('orphan.save') }}
            </AppButton>
            <AppButton variant="danger" block @click="emit('discard')">
                <Trash2 aria-hidden="true" />
                {{ t('orphan.discard') }}
            </AppButton>
        </div>
    </section>
</template>

<style scoped>
.orphan {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.orphan__title {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
}

.orphan__icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    margin-top: 1px;
    color: var(--color-notgot);
}

.orphan__text {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.orphan__actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-top: var(--space-2);
}
</style>
