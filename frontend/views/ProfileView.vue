<script setup>
/**
 * Profil: Name und Avatar aus dem QUIQQER-Backend, Darstellung, Sprache,
 * Abmelden. Einstellungen gelten sofort und werden dann gespeichert.
 */
import { useIonRouter } from '@ionic/vue';
import AppPage from '@/components/layout/AppPage.vue';
import { logout, updateSettings, useSession } from '@/composables/useSession.js';
import { showToast } from '@/composables/useToast.js';
import { LOCALE_NAMES, SUPPORTED_LOCALES, t } from '@/i18n/index.js';

const { user } = useSession();
const ionRouter = useIonRouter();

const themes = ['auto', 'light', 'dark'];
const languages = ['auto', ...SUPPORTED_LOCALES];

async function change(setting, value) {
    if (user.value.settings[setting] === value) {
        return;
    }

    try {
        await updateSettings({ [setting]: value });
        showToast(t('profile.saved'));
    } catch (error) {
        console.error(error);
        showToast(t('common.error'));
    }
}

async function signOut() {
    try {
        await logout();
    } finally {
        ionRouter.navigate('/login', 'root', 'replace');
    }
}
</script>

<template>
    <AppPage>
        <main v-if="user" class="profile">
            <h1 class="visually-hidden">{{ t('profile.title') }}</h1>

            <header class="profile__head">
                <img
                    v-if="user.avatarUrl"
                    class="profile__avatar"
                    :src="user.avatarUrl"
                    alt=""
                >
                <span v-else class="profile__avatar profile__avatar--initials" aria-hidden="true">
                    {{ user.initials }}
                </span>
                <p class="profile__name">{{ user.name }}</p>
            </header>

            <section class="profile__section">
                <h2 id="profile-theme" class="profile__heading">{{ t('profile.theme') }}</h2>
                <div class="profile__segments" role="radiogroup" aria-labelledby="profile-theme">
                    <button
                        v-for="theme in themes"
                        :key="theme"
                        class="profile__segment"
                        type="button"
                        role="radio"
                        :aria-checked="user.settings.theme === theme"
                        @click="change('theme', theme)"
                    >
                        {{ t('profile.themes.' + theme) }}
                    </button>
                </div>
            </section>

            <section class="profile__section">
                <h2 id="profile-language" class="profile__heading">{{ t('profile.language') }}</h2>
                <div class="profile__segments" role="radiogroup" aria-labelledby="profile-language">
                    <button
                        v-for="language in languages"
                        :key="language"
                        class="profile__segment"
                        type="button"
                        role="radio"
                        :aria-checked="user.settings.language === language"
                        @click="change('language', language)"
                    >
                        {{ language === 'auto' ? t('profile.languages.auto') : LOCALE_NAMES[language] }}
                    </button>
                </div>
            </section>

            <button class="profile__logout" type="button" @click="signOut">
                {{ t('profile.logout') }}
            </button>
        </main>
    </AppPage>
</template>

<style scoped>
.profile {
    display: flex;
    flex-direction: column;
    gap: var(--section-gap);
    max-width: var(--narrow-content-width);
    margin: 0 auto;
    padding: var(--space-10) var(--page-gutter);
}

.profile__head {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.profile__avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--avatar-size-lg);
    height: var(--avatar-size-lg);
    border-radius: 50%;
    object-fit: cover;
}

.profile__avatar--initials {
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-bold);
}

.profile__name {
    font-family: var(--font-display);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-bold);
}

.profile__section {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.profile__heading {
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    color: var(--color-text-2);
}

.profile__segments {
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: var(--space-1);
    padding: var(--space-1);
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
}

.profile__segment {
    min-height: var(--control-height);
    padding: 0 var(--space-3);
    border-radius: var(--radius-pill);
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    transition: background-color var(--duration-fast) var(--ease-out), color var(--duration-fast) var(--ease-out);
}

.profile__segment[aria-checked="true"] {
    background: var(--color-surface-1);
    color: var(--color-text-1);
    box-shadow: 0 0 0 1px var(--color-border);
}

.profile__logout {
    align-self: flex-start;
    min-height: var(--control-height);
    padding: 0 var(--space-6);
    border-radius: var(--radius-pill);
    border: 1px solid var(--color-border-strong);
    color: var(--color-danger);
    font-weight: var(--font-weight-semibold);
}

@media (hover: hover) {
    .profile__logout:hover {
        background: var(--color-surface-2);
    }
}
</style>
