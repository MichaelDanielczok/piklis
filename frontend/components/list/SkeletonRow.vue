<script setup>
/**
 * Platzhalter einer Listenzeile beim Laden: exakt in Endhöhe (64 px, Bild
 * 44 px, Ring 28 px) und mit denselben Zonen wie ListRow, damit beim
 * Erscheinen der Daten nichts springt. Flache Flächen ohne Schimmer.
 * `width` variiert die Namenslänge (Anteil 0–1).
 */
defineProps({
    width: {
        type: Number,
        default: 0.6
    }
});
</script>

<template>
    <li class="skeleton-row" aria-hidden="true">
        <span class="skeleton-row__handle"></span>
        <span class="skeleton-row__details">
            <span class="skeleton-row__thumb"></span>
            <span class="skeleton-row__text">
                <span class="skeleton-row__bar" :style="{ width: width * 100 + '%' }"></span>
            </span>
            <span class="skeleton-row__bar skeleton-row__bar--quantity"></span>
        </span>
        <span class="skeleton-row__check">
            <span class="skeleton-row__ring"></span>
        </span>
    </li>
</template>

<style scoped>
.skeleton-row {
    display: flex;
    align-items: stretch;
    height: var(--row-min-height);
    background: var(--color-surface-1);
}

.skeleton-row + .skeleton-row {
    border-top: 1px solid var(--color-border-subtle);
}

.skeleton-row__handle {
    flex: none;
    width: var(--handle-width);
}

.skeleton-row__details {
    display: flex;
    flex: 1;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
    padding-right: var(--space-3);
}

.skeleton-row__thumb {
    flex: none;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.skeleton-row__text {
    flex: 1;
    min-width: 0;
}

.skeleton-row__bar {
    display: block;
    height: var(--space-4);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton);
}

.skeleton-row__bar--quantity {
    flex: none;
    width: var(--space-14);
    background: var(--color-skeleton-2);
}

.skeleton-row__check {
    position: relative;
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--check-zone-width);
}

.skeleton-row__check::before {
    content: "";
    position: absolute;
    top: var(--space-3);
    bottom: var(--space-3);
    left: 0;
    width: 1px;
    background: var(--color-border-subtle);
}

.skeleton-row__ring {
    width: var(--check-ring-size);
    height: var(--check-ring-size);
    border: 2px solid var(--color-skeleton);
    border-radius: 50%;
}
</style>
