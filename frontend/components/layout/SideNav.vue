<script setup>
/**
 * Seitenleiste (Desktop): App-Icon und Name, Hauptziele mit grünem Pill für
 * das aktive Ziel, ganz unten das Benutzermenü.
 */
import AppLogo from '@/components/ui/AppLogo.vue';
import { t } from '@/i18n/index.js';
import NavLink from './NavLink.vue';
import UserMenu from './UserMenu.vue';
import { navItems } from './navItems.js';
</script>

<template>
    <aside class="side-nav">
        <div class="side-nav__brand">
            <AppLogo />
        </div>

        <nav class="side-nav__nav" :aria-label="t('nav.main')">
            <ul class="side-nav__list">
                <li v-for="item in navItems" :key="item.to">
                    <NavLink :to="item.to" :exact="item.exact" class="side-nav__link">
                        <component :is="item.icon" class="side-nav__icon" aria-hidden="true" />
                        {{ t(item.labelKey) }}
                    </NavLink>
                </li>
            </ul>
        </nav>

        <UserMenu class="side-nav__user" placement="sidebar" />
    </aside>
</template>

<style scoped>
.side-nav {
    flex-direction: column;
    flex: none;
    width: var(--sidebar-width);
    /* unter der Statusleiste der installierten App (iPad) */
    padding: calc(var(--space-6) + env(safe-area-inset-top, 0px)) var(--space-4) var(--space-4);
    background: var(--color-bg);
    border-right: 1px solid var(--color-border);
}

.side-nav__brand {
    padding: 0 var(--space-2) var(--space-8);
}

.side-nav__nav {
    display: flex;
    flex-direction: column;
    flex: 1;
}

.side-nav__list {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
}

.side-nav__link {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--tap-size-main);
    padding: 0 var(--space-3);
    border-radius: var(--radius-md);
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    transition: background-color var(--duration-fast) var(--ease-out), color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .side-nav__link:hover {
        background: var(--color-surface-2);
    }
}

.side-nav__link[aria-current="page"] {
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
}

.side-nav__icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.side-nav__user {
    margin-top: var(--space-4);
}
</style>
