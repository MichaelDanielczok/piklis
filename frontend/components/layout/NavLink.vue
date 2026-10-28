<script setup>
/**
 * Link auf ein Hauptziel der Navigation. Wechselt als „root“-Navigation
 * (ohne Vorwärts-Animation, ersetzt den Verlauf), wie Tabs.
 */
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useIonRouter } from '@ionic/vue';

const props = defineProps({
    to: {
        type: String,
        required: true
    },
    exact: {
        type: Boolean,
        default: false
    },
    // weitere Pfade, unter denen der Eintrag aktiv ist (z. B. /lists für „Listen“)
    prefixes: {
        type: Array,
        default: () => []
    }
});

const route = useRoute();
const router = useRouter();
const ionRouter = useIonRouter();

const href = computed(() => router.resolve(props.to).href);

const isActive = computed(() => {
    if (props.prefixes.some((prefix) => route.path === prefix || route.path.startsWith(prefix + '/'))) {
        return true;
    }

    if (props.exact) {
        return route.path === props.to;
    }

    return route.path === props.to || route.path.startsWith(props.to + '/');
});

function navigate() {
    if (route.fullPath !== props.to) {
        ionRouter.navigate(props.to, 'root', 'replace');
    }
}
</script>

<template>
    <a
        :href="href"
        :aria-current="isActive ? 'page' : undefined"
        @click.prevent="navigate"
    >
        <slot :is-active="isActive" />
    </a>
</template>
