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
    }
});

const route = useRoute();
const router = useRouter();
const ionRouter = useIonRouter();

const href = computed(() => router.resolve(props.to).href);

const isActive = computed(() => {
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
