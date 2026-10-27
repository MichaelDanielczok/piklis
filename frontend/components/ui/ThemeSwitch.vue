<script setup>
/**
 * Motivwahl Automatisch · Hell · Dunkel als Segmente. Gilt sofort und wird
 * dann gespeichert (Benutzerattribut pk.theme); schlägt das Speichern fehl,
 * gilt wieder das vorige Motiv.
 *
 * `menu`: im Benutzermenü als menuitemradio (Pfeiltasten steuert das Menü),
 * sonst als radiogroup mit Pfeiltasten und einem Tab-Stopp.
 */
import { ref } from 'vue';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { updateSettings, useSession } from '@/composables/useSession.js';
import { showToast } from '@/composables/useToast.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    menu: {
        type: Boolean,
        default: false
    }
});

const { user } = useSession();
const root = ref(null);

const themes = [
    { value: 'auto', icon: Monitor },
    { value: 'light', icon: Sun },
    { value: 'dark', icon: Moon }
];

function current() {
    return user.value?.settings?.theme ?? 'auto';
}

async function choose(theme) {
    if (current() === theme) {
        return;
    }

    try {
        await updateSettings({ theme });
    } catch (error) {
        console.error(error);
        showToast(t('common.error'), { icon: 'info' });
    }
}

function onKeydown(event, index) {
    if (props.menu) {
        return;
    }

    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

    if (!step) {
        return;
    }

    event.preventDefault();

    const next = themes[(index + step + themes.length) % themes.length];
    choose(next.value);
    root.value?.querySelector(`[data-theme-value="${next.value}"]`)?.focus();
}
</script>

<template>
    <div
        ref="root"
        class="theme-switch"
        :role="menu ? 'group' : 'radiogroup'"
        :aria-label="label"
    >
        <button
            v-for="(theme, index) in themes"
            :key="theme.value"
            type="button"
            class="theme-switch__option"
            :role="menu ? 'menuitemradio' : 'radio'"
            :aria-checked="current() === theme.value"
            :tabindex="menu ? -1 : (current() === theme.value ? 0 : -1)"
            :data-theme-value="theme.value"
            @click="choose(theme.value)"
            @keydown="onKeydown($event, index)"
        >
            <component :is="theme.icon" class="theme-switch__icon" aria-hidden="true" />
            <span class="theme-switch__label">{{ t('theme.' + theme.value) }}</span>
        </button>
    </div>
</template>

<style scoped>
.theme-switch {
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: var(--space-1);
    padding: var(--space-1);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.theme-switch__option {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-1);
    min-height: var(--space-14);
    padding: var(--space-1);
    border-radius: var(--radius-sm);
    color: var(--color-text-2);
    font-size: var(--font-size-caption);
    font-weight: var(--font-weight-semibold);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .theme-switch__option:hover {
        color: var(--color-text-1);
    }
}

.theme-switch__option[aria-checked="true"] {
    background: var(--color-surface-1);
    color: var(--color-accent-text);
    box-shadow: var(--shadow-1), 0 0 0 1px var(--color-border);
}

.theme-switch__option:focus-visible {
    outline-offset: 0;
}

.theme-switch__icon {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.theme-switch__label {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
