<script setup>
/**
 * Profil (aus dem Benutzermenü): Name und Bild aus dem QUIQQER-Backend,
 * Hinweis auf das Recht Produktpflege, Abmelden.
 */
import { useIonRouter } from '@ionic/vue';
import { LogOut, ShieldCheck } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import UserAvatar from '@/components/ui/UserAvatar.vue';
import { logout, useSession } from '@/composables/useSession.js';
import { t } from '@/i18n/index.js';

const { user, canManageProducts } = useSession();
const ionRouter = useIonRouter();

async function signOut() {
    try {
        await logout();
    } finally {
        ionRouter.navigate('/login', 'root', 'replace');
    }
}
</script>

<template>
    <AppPage :title="t('profile.title')" back width="narrow">
        <div v-if="user" class="view-body">
            <section class="profile__card">
                <UserAvatar :user="user" size="lg" />
                <div class="profile__text">
                    <p class="profile__name">{{ user.name }}</p>
                    <p class="profile__hint">{{ t('profile.nameHint') }}</p>
                </div>
            </section>

            <p v-if="canManageProducts" class="profile__badge">
                <ShieldCheck class="profile__badge-icon" aria-hidden="true" />
                {{ t('profile.productManager') }}
            </p>

            <AppButton variant="secondary" class="profile__logout" @click="signOut">
                <LogOut aria-hidden="true" />
                {{ t('profile.logout') }}
            </AppButton>
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-2) var(--page-gutter) 0;
}

.profile__card {
    display: flex;
    align-items: center;
    gap: var(--space-4);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.profile__text {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
}

.profile__name {
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}

.profile__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.profile__badge {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
}

.profile__badge-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.profile__logout {
    align-self: flex-start;
    color: var(--color-danger);
}
</style>
