<script setup>
/**
 * Scannen aus der Eingabeleiste: die vorhandene Kamera-Erkennung
 * (`BarcodeScanner`). Ein Barcode aus dem Katalog geht als `found` an die
 * Leiste, das klappt auch offline; ein unbekannter bleibt hier ein Hinweis.
 * Schritt 20 ergänzt den Weg über Open Food Facts.
 *
 * v-model:open · Emits: found(product)
 */
import { ref, watch } from 'vue';
import { ScanBarcode } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import BarcodeScanner from '@/components/products/BarcodeScanner.vue';
import { useProducts } from '@/composables/useProducts.js';
import { t } from '@/i18n/index.js';

const emit = defineEmits(['found']);
const open = defineModel('open', { type: Boolean, default: false });

const { catalog } = useProducts();

/** Zuletzt gelesener Barcode ohne Produkt; leer: Kamera läuft. */
const unknown = ref('');

watch(open, () => {
    unknown.value = '';
});

function onDetected(barcode) {
    const product = catalog.value.find((item) => item.barcode === barcode);

    if (!product) {
        unknown.value = barcode;
        return;
    }

    open.value = false;
    emit('found', product);
}
</script>

<template>
    <AppSheet v-model:open="open" :title="t('addBar.scanTitle')">
        <BarcodeScanner v-if="!unknown" @detected="onDetected" />

        <div v-else class="add-bar-scan" role="status">
            <p class="add-bar-scan__text">{{ t('addBar.scanUnknown', { barcode: unknown }) }}</p>
            <AppButton variant="secondary" block @click="unknown = ''">
                <ScanBarcode aria-hidden="true" />
                {{ t('addBar.scanAgain') }}
            </AppButton>
        </div>

        <template #footer>
            <AppButton variant="secondary" @click="open = false">{{ t('common.close') }}</AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.add-bar-scan {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.add-bar-scan__text {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}
</style>
