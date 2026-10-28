<script setup>
/**
 * Sheet „…“ an einem Mitglied (nur Administratoren, nicht für sich selbst):
 * zum Administrator machen bzw. Administratorrechte entziehen, aus dem
 * Haushalt entfernen (Rückfrage im selben Sheet). Der Server prüft die
 * Rechte; „letzter Administrator“ kommt als Fehlermeldung.
 *
 * v-model:open · `member` { id, name, role, … }
 */
import { ref, watch } from 'vue';
import { ShieldCheck, ShieldOff, UserMinus } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import { ApiError } from '@/api/index.js';
import { removeMember, setMemberRole } from '@/composables/useHousehold.js';
import { showToast } from '@/composables/useToast.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    member: {
        type: Object,
        default: null
    }
});

const open = defineModel('open', { type: Boolean, default: false });

const confirmRemove = ref(false);
const pending = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        confirmRemove.value = false;
    }
});

function errorText(error) {
    if (error instanceof ApiError && error.status === 0) {
        return t('household.errors.network');
    }

    const key = 'household.errors.' + (error?.code ?? '');
    const text = t(key);

    return text === key ? t('common.error') : text;
}

async function run(action, toastKey) {
    if (pending.value || !props.member) {
        return;
    }

    const name = props.member.name;

    pending.value = true;

    try {
        await action();
        open.value = false;
        showToast(t(toastKey, { name }), { icon: 'info' });
    } catch (error) {
        console.error(error);
        showToast(errorText(error), { icon: 'notgot' });
    } finally {
        pending.value = false;
    }
}

function toggleAdmin() {
    const makeAdmin = props.member?.role !== 'admin';

    run(
        () => setMemberRole(props.member.id, makeAdmin ? 'admin' : 'member'),
        makeAdmin ? 'household.madeAdmin' : 'household.madeMember'
    );
}

function remove() {
    run(() => removeMember(props.member.id), 'household.removed');
}
</script>

<template>
    <AppSheet
        v-model:open="open"
        :title="confirmRemove ? t('household.removeTitle', { name: member?.name ?? '' }) : (member?.name ?? '')"
        :subtitle="confirmRemove ? '' : t(member?.role === 'admin' ? 'household.roleAdmin' : 'household.roleMember')"
    >
        <p v-if="confirmRemove" class="member-sheet__text">
            {{ t('household.removeText', { name: member?.name ?? '' }) }}
        </p>

        <div v-else class="member-sheet__actions">
            <AppButton variant="secondary" block :disabled="pending" @click="toggleAdmin">
                <component :is="member?.role === 'admin' ? ShieldOff : ShieldCheck" aria-hidden="true" />
                {{ member?.role === 'admin' ? t('household.removeAdmin') : t('household.makeAdmin') }}
            </AppButton>
            <p v-if="member?.role !== 'admin'" class="member-sheet__hint">{{ t('household.makeAdminHint') }}</p>
            <AppButton variant="danger" block :disabled="pending" @click="confirmRemove = true">
                <UserMinus aria-hidden="true" />
                {{ t('household.removeMember') }}
            </AppButton>
        </div>

        <template v-if="confirmRemove" #footer>
            <AppButton variant="secondary" @click="confirmRemove = false">{{ t('common.cancel') }}</AppButton>
            <AppButton variant="danger-solid" :disabled="pending" @click="remove">{{ t('household.removeConfirm') }}</AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.member-sheet__actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.member-sheet__text {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.member-sheet__hint {
    margin-top: calc(var(--space-2) * -1);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
