<script setup>
/**
 * App-Icon „Zettelstapel“ (Designpaket, piklis-icon.svg) mit dem Namen in der
 * Systemschrift, ohne eigenen Schriftzug. Farben fest wie im Icon.
 */
import { t } from '@/i18n/index.js';
</script>

<template>
    <span class="app-logo">
        <svg class="app-logo__icon" viewBox="0 0 96 96" aria-hidden="true" focusable="false">
            <rect width="96" height="96" rx="22" fill="#1F6E4E" />
            <rect x="34" y="16" width="44" height="56" rx="9" fill="#8FCBAF" transform="rotate(9 56 44)" />
            <g transform="rotate(-6 44 52)">
                <rect x="20" y="24" width="46" height="56" rx="9" fill="#FFFFFF" />
                <rect x="27" y="35" width="16" height="16" rx="4.5" fill="#1F6E4E" />
                <path d="M31 43l2.8 2.8 5.6-6" fill="none" stroke="#FFFFFF" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M49 43h10M27 64h32" stroke="#1F6E4E" stroke-width="7" stroke-linecap="round" />
            </g>
        </svg>
        <span class="app-logo__name">{{ t('common.appName') }}</span>
    </span>
</template>

<style scoped>
.app-logo {
    display: inline-flex;
    align-items: center;
    gap: var(--space-3);
}

.app-logo__icon {
    width: var(--space-8);
    height: var(--space-8);
}

.app-logo__name {
    color: var(--color-text-1);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}
</style>
