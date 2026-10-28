<script setup>
/**
 * Benutzermenü am Avatar: Name, Profil, Haushalt, Einstellungen, Darstellung
 * (Motiv Automatisch · Hell · Dunkel direkt im Menü), im Entwicklungsmodus
 * die Musterseite, Abmelden.
 *
 * placement: header (Mobil: Avatar oben rechts, Menü klappt nach unten) ·
 * sidebar (Desktop: Avatar und Name unten in der Seitenleiste, Menü nach oben).
 *
 * Tastatur wie ein Menü-Knopf: Enter/Leertaste/Pfeil runter öffnet und setzt
 * den Fokus auf den ersten Eintrag, Pfeile wechseln, Pos1/Ende springen,
 * Escape schließt und gibt den Fokus an den Knopf zurück. Tab oder ein Klick
 * daneben schließt das Menü.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useIonRouter } from '@ionic/vue';
import { ChevronsUpDown, House, LayoutTemplate, LogOut, Settings, Shapes, UserRound } from '@lucide/vue';
import ThemeSwitch from '@/components/ui/ThemeSwitch.vue';
import UserAvatar from '@/components/ui/UserAvatar.vue';
import { logout, useSession } from '@/composables/useSession.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    placement: {
        type: String,
        default: 'header',
        validator: (value) => ['header', 'sidebar'].includes(value)
    }
});

const { user, household } = useSession();
const route = useRoute();
const ionRouter = useIonRouter();

const open = ref(false);
const root = ref(null);
const button = ref(null);
const menu = ref(null);
const menuId = useId();
const themeLabelId = useId();

const links = computed(() => [
    { to: '/profile', labelKey: 'menu.profile', icon: UserRound },
    { to: '/household', labelKey: 'menu.household', icon: House },
    { to: '/settings', labelKey: 'menu.settings', icon: Settings },
    // Musterseite der Grundkomponenten und Icon-Übersicht nur im Entwicklungsserver
    ...(import.meta.env.DEV
        ? [
            { to: '/components', labelKey: 'menu.components', icon: LayoutTemplate },
            { to: '/icons', labelKey: 'menu.icons', icon: Shapes }
        ]
        : [])
]);

function items() {
    return menu.value ? [...menu.value.querySelectorAll('[role="menuitem"], [role="menuitemradio"]')] : [];
}

async function openMenu(focus = 'first') {
    open.value = true;
    await nextTick();

    const all = items();
    (focus === 'last' ? all[all.length - 1] : all[0])?.focus();
}

function closeMenu({ restoreFocus = false } = {}) {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (restoreFocus) {
        button.value?.focus();
    }
}

function toggle() {
    if (open.value) {
        closeMenu();
    } else {
        openMenu();
    }
}

function onButtonKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        openMenu('first');
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        openMenu('last');
    }
}

function onMenuKeydown(event) {
    const all = items();
    const index = all.indexOf(document.activeElement);

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            all[(index + 1) % all.length]?.focus();
            break;
        case 'ArrowUp':
            event.preventDefault();
            all[(index - 1 + all.length) % all.length]?.focus();
            break;
        case 'Home':
            event.preventDefault();
            all[0]?.focus();
            break;
        case 'End':
            event.preventDefault();
            all[all.length - 1]?.focus();
            break;
        case 'Escape':
            event.preventDefault();
            closeMenu({ restoreFocus: true });
            break;
        case 'Tab':
            closeMenu();
            break;
    }
}

function onDocumentPointerDown(event) {
    if (root.value && !root.value.contains(event.target)) {
        closeMenu();
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onDocumentPointerDown);
    } else {
        document.removeEventListener('pointerdown', onDocumentPointerDown);
    }
});

// Seitenwechsel (auch über Zurück) schließt das Menü
watch(() => route.fullPath, () => closeMenu());

onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown));

function navigate(to) {
    closeMenu();

    if (route.path !== to) {
        ionRouter.navigate(to, 'forward', 'push');
    }
}

async function signOut() {
    closeMenu();

    try {
        await logout();
    } finally {
        ionRouter.navigate('/login', 'root', 'replace');
    }
}
</script>

<template>
    <div v-if="user" ref="root" class="user-menu" :class="'user-menu--' + placement">
        <button
            ref="button"
            type="button"
            class="user-menu__button"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="menuId"
            :aria-label="props.placement === 'header' ? t('menu.open', { name: user.name }) : undefined"
            @click="toggle"
            @keydown="onButtonKeydown"
        >
            <UserAvatar :user="user" />
            <template v-if="placement === 'sidebar'">
                <span class="user-menu__button-name">{{ user.name }}</span>
                <ChevronsUpDown class="user-menu__chevron" aria-hidden="true" />
            </template>
        </button>

        <Transition name="user-menu">
            <div
                v-show="open"
                :id="menuId"
                ref="menu"
                class="user-menu__panel"
                role="menu"
                :aria-label="t('menu.label')"
                @keydown="onMenuKeydown"
            >
                <p class="user-menu__name" role="presentation">
                    {{ user.name }}
                    <span class="user-menu__household">{{ household?.name ?? t('menu.noHousehold') }}</span>
                </p>

                <a
                    v-for="link in links"
                    :key="link.to"
                    :href="'#' + link.to"
                    class="user-menu__item"
                    role="menuitem"
                    tabindex="-1"
                    :aria-current="route.path === link.to ? 'page' : undefined"
                    @click.prevent="navigate(link.to)"
                >
                    <component :is="link.icon" class="user-menu__icon" aria-hidden="true" />
                    {{ t(link.labelKey) }}
                </a>

                <div class="user-menu__separator" role="separator"></div>

                <div class="user-menu__theme" role="none">
                    <p :id="themeLabelId" class="user-menu__theme-label" role="presentation">{{ t('menu.theme') }}</p>
                    <ThemeSwitch :label="t('menu.theme')" menu />
                </div>

                <div class="user-menu__separator" role="separator"></div>

                <button
                    type="button"
                    class="user-menu__item user-menu__item--danger"
                    role="menuitem"
                    tabindex="-1"
                    @click="signOut"
                >
                    <LogOut class="user-menu__icon" aria-hidden="true" />
                    {{ t('menu.logout') }}
                </button>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.user-menu {
    position: relative;
}

.user-menu__button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.user-menu__button:active {
    transform: scale(var(--press-scale));
}

.user-menu--sidebar .user-menu__button {
    gap: var(--space-3);
    justify-content: flex-start;
    width: 100%;
    height: auto;
    min-height: var(--space-14);
    padding: var(--space-2);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
    text-align: left;
}

@media (hover: hover) {
    .user-menu--sidebar .user-menu__button:hover {
        background: var(--color-surface-3);
    }
}

.user-menu__button-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-menu__chevron {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    margin-right: var(--space-1);
    color: var(--color-text-2);
}

.user-menu__panel {
    position: absolute;
    top: calc(100% + var(--space-2));
    right: 0;
    z-index: 30;
    display: flex;
    flex-direction: column;
    width: max-content;
    min-width: var(--menu-min-width);
    padding: var(--space-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    box-shadow: var(--shadow-menu);
    transform-origin: top right;
}

.user-menu--sidebar .user-menu__panel {
    top: auto;
    right: auto;
    bottom: calc(100% + var(--space-2));
    left: 0;
    min-width: max(100%, var(--menu-min-width));
    transform-origin: bottom left;
}

.user-menu__name {
    padding: var(--space-2) var(--space-3) var(--space-3);
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-menu__household {
    display: block;
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-regular);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
}

.user-menu__item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--control-height);
    padding: 0 var(--space-3);
    border-radius: var(--radius-sm);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
    text-align: left;
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .user-menu__item:hover {
        background: var(--color-surface-2);
    }
}

.user-menu__item:focus-visible,
.user-menu__theme :deep([role="menuitemradio"]:focus-visible) {
    outline: none;
    box-shadow: inset 0 0 0 var(--focus-ring-width) var(--focus-ring-color);
}

.user-menu__item[aria-current="page"] {
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
}

.user-menu__item--danger {
    color: var(--color-danger);
}

.user-menu__icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-text-2);
}

.user-menu__item[aria-current="page"] .user-menu__icon,
.user-menu__item--danger .user-menu__icon {
    color: currentColor;
}

.user-menu__theme {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-1) var(--space-1) var(--space-1);
}

.user-menu__theme-label {
    padding: 0 var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
}

.user-menu__separator {
    height: 1px;
    margin: var(--space-2) 0;
    background: var(--color-border);
}

.user-menu-enter-active,
.user-menu-leave-active {
    transition:
        opacity var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.user-menu-enter-from,
.user-menu-leave-to {
    opacity: 0;
    transform: scale(0.96);
}
</style>
