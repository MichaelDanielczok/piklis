<script setup>
/**
 * Seitenleiste (Desktop): App-Name, Ziele, Profil ganz unten.
 */
import { t } from '@/i18n/index.js';
import NavLink from './NavLink.vue';
import { navItems } from './navItems.js';

const mainItems = navItems.filter((item) => item.to !== '/profile');
const profileItem = navItems.find((item) => item.to === '/profile');
</script>

<template>
    <aside class="side-nav">
        <div class="side-nav__brand">{{ t('common.appName') }}</div>

        <nav class="side-nav__nav" :aria-label="t('nav.main')">
            <ul class="side-nav__list">
                <li v-for="item in mainItems" :key="item.to">
                    <NavLink :to="item.to" :exact="item.exact" class="side-nav__link">
                        <component :is="item.icon" class="side-nav__icon" aria-hidden="true" />
                        {{ t(item.labelKey) }}
                    </NavLink>
                </li>
            </ul>

            <NavLink :to="profileItem.to" class="side-nav__link side-nav__link--bottom">
                <component :is="profileItem.icon" class="side-nav__icon" aria-hidden="true" />
                {{ t(profileItem.labelKey) }}
            </NavLink>
        </nav>
    </aside>
</template>

<style scoped>
.side-nav {
    flex-direction: column;
    flex: none;
    width: var(--sidebar-width);
    /* unter der Statusleiste der installierten App (iPad) */
    padding: calc(var(--space-8) + env(safe-area-inset-top, 0px)) var(--space-4) var(--space-6);
    background: var(--color-surface-1);
    border-right: 1px solid var(--color-border);
}

.side-nav__brand {
    padding: 0 var(--space-3) var(--space-8);
    font-family: var(--font-display);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-bold);
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
    min-height: var(--control-height);
    padding: 0 var(--space-4);
    border-radius: var(--radius-pill);
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
    transition: background-color var(--duration-fast) var(--ease-out), color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .side-nav__link:hover {
        background: var(--color-surface-2);
        color: var(--color-text-1);
    }
}

.side-nav__link[aria-current="page"] {
    background: var(--color-surface-3);
    color: var(--color-text-1);
    font-weight: var(--font-weight-semibold);
}

.side-nav__link[aria-current="page"] .side-nav__icon {
    color: var(--color-accent);
}

.side-nav__icon {
    flex: none;
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.side-nav__link--bottom {
    margin-top: auto;
}
</style>
