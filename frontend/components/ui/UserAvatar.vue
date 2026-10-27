<script setup>
/**
 * Benutzerbild aus dem QUIQQER-Backend, sonst Anfangsbuchstaben auf Grün.
 * Dekorativ: Der Name steht am Knopf bzw. daneben.
 */
defineProps({
    user: {
        type: Object,
        required: true
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['md', 'lg'].includes(value)
    }
});
</script>

<template>
    <img
        v-if="user.avatarUrl"
        class="user-avatar"
        :class="'user-avatar--' + size"
        :src="user.avatarUrl"
        alt=""
    >
    <span v-else class="user-avatar user-avatar--initials" :class="'user-avatar--' + size" aria-hidden="true">
        {{ user.initials }}
    </span>
</template>

<style scoped>
.user-avatar {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--avatar-size);
    height: var(--avatar-size);
    border-radius: 50%;
    object-fit: cover;
}

.user-avatar--lg {
    width: var(--avatar-size-lg);
    height: var(--avatar-size-lg);
}

.user-avatar--initials {
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
}

.user-avatar--lg.user-avatar--initials {
    font-size: var(--font-size-h2);
}
</style>
