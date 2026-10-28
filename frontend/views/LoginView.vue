<script setup>
/**
 * Anmeldung mit QUIQQER-Benutzer (Core-Ajax ajax_users_login). Nach Erfolg
 * zurück zur Seite aus `?redirect=`, sonst zur Startseite. Aus einem
 * Einladungslink (`/invite/…`) erklärt ein Hinweis, dass ein vorhandenes
 * Konto nötig ist.
 */
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AppLogo from '@/components/ui/AppLogo.vue';
import { login } from '@/composables/useSession.js';
import { ApiError } from '@/api/index.js';
import { t } from '@/i18n/index.js';

const route = useRoute();
const router = useRouter();

/** Kommt man über einen Einladungslink, erklärt ein Hinweis die Anmeldung. */
const fromInvite = computed(() => typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/invite/'));

const username = ref('');
const password = ref('');
const pending = ref(false);
const error = ref('');

async function submit() {
    if (pending.value) {
        return;
    }

    pending.value = true;
    error.value = '';

    try {
        await login(username.value.trim(), password.value);

        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
        await router.replace(redirect.startsWith('/') ? redirect : '/');
    } catch (exception) {
        if (exception instanceof ApiError && exception.status === 0) {
            error.value = t('common.offline');
        } else if (exception instanceof ApiError && exception.code === 'ajax') {
            // Meldung des Cores (Passwort falsch, gesperrt …), schon übersetzt
            error.value = exception.message || t('login.failed');
        } else {
            error.value = t('login.failed');
        }
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <AppPage bare>
        <main class="login">
            <form class="login__card" @submit.prevent="submit">
                <AppLogo class="login__logo" />
                <h1 class="login__title">{{ t('login.title') }}</h1>
                <p v-if="fromInvite" class="login__hint">{{ t('login.inviteHint') }}</p>

                <label class="login__field">
                    <span class="login__label">{{ t('login.username') }}</span>
                    <input
                        v-model="username"
                        class="login__input"
                        type="text"
                        name="username"
                        autocomplete="username"
                        autocapitalize="none"
                        required
                    >
                </label>

                <label class="login__field">
                    <span class="login__label">{{ t('login.password') }}</span>
                    <input
                        v-model="password"
                        class="login__input"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <p v-if="error" class="login__error" role="alert">{{ error }}</p>

                <button class="login__submit" type="submit" :disabled="pending">
                    {{ pending ? t('common.loading') : t('login.submit') }}
                </button>
            </form>
        </main>
    </AppPage>
</template>

<style scoped>
.login__hint {
    margin-top: calc(var(--space-2) * -1);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.login {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    min-height: 100dvh;
    padding: var(--space-6) var(--page-gutter);
}

.login__card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: var(--login-card-width);
    padding: var(--space-8) var(--space-6);
    border-radius: var(--radius-lg);
    background: var(--color-surface-1);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-1);
}

.login__logo {
    margin-bottom: var(--space-2);
}

.login__title {
    margin-bottom: var(--space-2);
    font-size: var(--font-size-h1);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h1);
}

.login__field {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.login__label {
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    color: var(--color-text-2);
}

.login__input {
    height: var(--input-height);
    padding: 0 var(--space-4);
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    /* 16 px: iOS zoomt sonst beim Fokus */
    font: inherit;
    font-size: var(--font-size-title);
}

.login__input:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 0;
}

.login__error {
    color: var(--color-danger);
    font-size: var(--font-size-small);
}

.login__submit {
    height: var(--button-height);
    margin-top: var(--space-2);
    border-radius: var(--radius-md);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    transition: background-color var(--duration-fast) var(--ease-out), transform var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .login__submit:hover {
        background: var(--color-accent-hover);
    }
}

.login__submit:active {
    background: var(--color-accent-press);
    transform: scale(var(--press-scale));
}

.login__submit:disabled {
    opacity: 0.6;
    cursor: default;
}
</style>
