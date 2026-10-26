<script setup>
/**
 * Startseite: Platzhalter für den eigentlichen Inhalt der App.
 */
import AppPage from '@/components/layout/AppPage.vue';
import { useSession } from '@/composables/useSession.js';
import { t } from '@/i18n/index.js';

const { user } = useSession();
</script>

<template>
    <AppPage>
        <main class="home">
            <h1 class="home__title">{{ t('home.greeting', { name: user?.name ?? '' }) }}</h1>
            <p class="home__intro">{{ t('home.intro') }}</p>
        </main>
    </AppPage>
</template>

<style scoped>
.home {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-10) var(--page-gutter);
}

.home__title {
    font-family: var(--font-display);
    font-size: var(--font-size-h1);
    line-height: var(--line-height-heading);
}

.home__intro {
    color: var(--color-text-2);
    line-height: var(--line-height-text);
}
</style>
