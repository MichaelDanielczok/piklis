<script setup>
/**
 * Ladezustand einer Listenkarte: dieselben Zeilen und Höhen wie ListCard
 * (Kopf 28 px, Fortschritt 20 px, Bilder 44 px), flach ohne Schimmer.
 * `width` variiert die Namenslänge (Anteil 0–1).
 */
defineProps({
    width: {
        type: Number,
        default: 0.4
    }
});
</script>

<template>
    <div class="list-card-skeleton" aria-hidden="true">
        <span class="list-card-skeleton__row list-card-skeleton__row--head">
            <span class="list-card-skeleton__bar list-card-skeleton__bar--name" :style="{ width: width * 100 + '%' }"></span>
            <span class="list-card-skeleton__chip"></span>
        </span>
        <span class="list-card-skeleton__row list-card-skeleton__row--progress">
            <span class="list-card-skeleton__track"></span>
            <span class="list-card-skeleton__bar list-card-skeleton__bar--count"></span>
        </span>
        <span class="list-card-skeleton__row list-card-skeleton__row--preview">
            <span v-for="index in 3" :key="index" class="list-card-skeleton__thumb"></span>
        </span>
    </div>
</template>

<style scoped>
.list-card-skeleton {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.list-card-skeleton__row {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.list-card-skeleton__row--head {
    justify-content: space-between;
    height: var(--line-height-h2);
}

.list-card-skeleton__row--progress {
    height: var(--line-height-qty);
}

.list-card-skeleton__row--preview {
    gap: var(--space-2);
    height: var(--thumb-size);
}

.list-card-skeleton__bar {
    display: block;
    height: var(--space-5);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton);
}

.list-card-skeleton__bar--count {
    flex: none;
    width: var(--space-14);
    height: var(--space-4);
    background: var(--color-skeleton-2);
}

.list-card-skeleton__chip {
    flex: none;
    width: 5.5rem;
    height: var(--line-height-h2);
    border-radius: var(--radius-pill);
    background: var(--color-skeleton-2);
}

.list-card-skeleton__track {
    flex: 1;
    height: var(--progress-height);
    border-radius: var(--radius-pill);
    background: var(--color-skeleton-2);
}

.list-card-skeleton__thumb {
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton-2);
}
</style>
