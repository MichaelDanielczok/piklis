<script setup>
/**
 * Schwebende Glas-Navigation unten (Mobil und Tablet).
 */
import { t } from '@/i18n/index.js';
import NavLink from './NavLink.vue';
import { navItems } from './navItems.js';
</script>

<template>
    <nav class="mobile-nav" :aria-label="t('nav.main')">
        <ul class="mobile-nav__list" :style="{ '--nav-count': navItems.length }">
            <li v-for="item in navItems" :key="item.to">
                <NavLink :to="item.to" :exact="item.exact" class="mobile-nav__link">
                    <component :is="item.icon" class="mobile-nav__icon" aria-hidden="true" />
                    <span class="mobile-nav__label">{{ t(item.labelKey) }}</span>
                </NavLink>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
.mobile-nav {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: var(--mobile-nav-bottom);
    z-index: 10;
    height: var(--mobile-nav-height);
    border-radius: var(--radius-pill);
    background: var(--nav-glass-bg);
    border: 1px solid var(--nav-glass-border);
    box-shadow: var(--shadow-float);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
}

.mobile-nav__list {
    display: grid;
    grid-template-columns: repeat(var(--nav-count), minmax(0, 1fr));
    height: 100%;
    padding: 0 var(--space-2);
}

.mobile-nav__link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-1);
    height: 100%;
    color: var(--color-text-2);
    font-size: var(--font-size-caption);
    font-weight: var(--font-weight-semibold);
    transition: color var(--duration-fast) var(--ease-out), transform var(--duration-fast) var(--ease-out);
}

.mobile-nav__link:active {
    transform: scale(var(--press-scale));
}

.mobile-nav__link[aria-current="page"] {
    color: var(--color-accent-text);
}

.mobile-nav__icon {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.mobile-nav__label {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (min-width: 45em) {
    .mobile-nav {
        left: 50%;
        right: auto;
        width: var(--mobile-nav-max-width);
        transform: translateX(-50%);
    }
}
</style>
