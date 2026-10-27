<script setup>
/**
 * Produkt anlegen oder bearbeiten (Designpaket 11, ohne Kurzname, Zutaten
 * und Nährwerte). Nur der Name ist Pflicht. Das Foto (`ProductImageField`)
 * wird nach den Produktdaten hochgeladen, beim Anlegen also erst, wenn das
 * Produkt existiert.
 *
 * Beim Anlegen schlägt das Formular passende Produkte aus dem gemeinsamen
 * Katalog vor („Gouda“ → „Gouda jung“). Ein vorhandenes lässt sich öffnen,
 * oder man legt ausdrücklich ein neues an; ähnliche Namen werden nie
 * zusammengeführt.
 *
 * Speichert selbst (`createProduct`/`updateProduct`) und meldet das Ergebnis.
 * Props: product (null = neu), initialName · Emits: saved(product), cancel,
 * open(product)
 */
import { computed, nextTick, onMounted, reactive, ref, useId } from 'vue';
import { CircleAlert } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import IconPicker from './IconPicker.vue';
import ProductImage from './ProductImage.vue';
import ProductImageField from './ProductImageField.vue';
import {
    createProduct,
    removeProductImage,
    setProductImage,
    updateProduct,
    useProducts
} from '@/composables/useProducts.js';
import { showToast } from '@/composables/useToast.js';
import { CATEGORIES, DEFAULT_ICON, iconOf } from '@/products/catalog.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 120;
const SIZE_MAX = 50;
const DESCRIPTION_MAX = 2000;
const SUGGESTION_LIMIT = 5;

const props = defineProps({
    product: {
        type: Object,
        default: null
    },
    initialName: {
        type: String,
        default: ''
    },
    autofocus: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['saved', 'cancel', 'open']);

const { search } = useProducts();

const isNew = computed(() => props.product === null);

const form = reactive({
    name: props.product?.name ?? props.initialName,
    packageSize: props.product?.packageSize ?? '',
    category: props.product?.category ?? '',
    icon: iconOf(props.product),
    description: props.product?.description ?? ''
});

const imageChange = ref(null);
const pickerOpen = ref(false);
const pending = ref(false);
const submitted = ref(false);
const serverError = ref('');
const nameInput = ref(null);

const ids = {
    name: useId(),
    nameHint: useId(),
    nameError: useId(),
    suggestions: useId(),
    packageSize: useId(),
    category: useId(),
    description: useId()
};

const nameError = computed(() => {
    const name = form.name.trim();

    if (name === '') {
        return submitted.value ? t('productForm.nameRequired') : '';
    }

    return name.length > NAME_MAX ? t('productForm.nameTooLong', { count: NAME_MAX }) : '';
});

// Vorschläge nur beim Anlegen: vorhandene Produkte mit passendem Namen
const suggestions = computed(() => {
    if (!isNew.value || form.name.trim().length < 2) {
        return [];
    }

    return search(form.name, { limit: SUGGESTION_LIMIT });
});

function fieldsFromForm() {
    return {
        name: form.name.trim(),
        packageSize: form.packageSize.trim() || null,
        category: form.category || null,
        icon: form.icon === DEFAULT_ICON ? null : form.icon,
        description: form.description.trim() || null
    };
}

// Beim Bearbeiten nur geänderte Felder senden
function changedFields() {
    const fields = fieldsFromForm();
    const changes = {};

    for (const [key, value] of Object.entries(fields)) {
        if ((props.product[key] ?? null) !== value) {
            changes[key] = value;
        }
    }

    return changes;
}

async function submit() {
    submitted.value = true;
    serverError.value = '';

    if (nameError.value) {
        nameInput.value?.focus();
        return;
    }

    pending.value = true;

    try {
        if (isNew.value) {
            const created = await createProduct(fieldsFromForm());
            const product = await saveImage(created);

            if (product) {
                showToast(t('products.createdToast', { name: product.name }), { icon: 'done' });
            }

            emit('saved', product ?? created);
            return;
        }

        const changes = changedFields();
        const updated = Object.keys(changes).length > 0
            ? await updateProduct(props.product.id, changes)
            : props.product;
        const product = await saveImage(updated);

        if (product) {
            showToast(t('products.savedToast'), { icon: 'done' });
        }

        emit('saved', product ?? updated);
    } catch (error) {
        serverError.value = t(error?.status === 403 ? 'products.forbidden' : 'products.saveError');
    } finally {
        pending.value = false;
    }
}

/**
 * Lädt das Foto hoch oder entfernt es, nachdem die Produktdaten gespeichert
 * sind. Scheitert das, ist das Produkt trotzdem gespeichert: Hinweis, null.
 */
async function saveImage(product) {
    const imageChangeValue = imageChange.value;

    if (!imageChangeValue) {
        return product;
    }

    try {
        const saved = imageChangeValue.type === 'remove'
            ? await removeProductImage(product.id)
            : await setProductImage(product.id, {
                image: imageChangeValue.image,
                original: imageChangeValue.original
            });

        imageChange.value = null;

        return saved;
    } catch (error) {
        console.error(error);
        showToast(t(error?.status === 413 ? 'productImage.errors.uploadTooLarge' : 'productImage.errors.upload'), {
            icon: 'notgot'
        });

        return null;
    }
}

onMounted(async () => {
    if (props.autofocus) {
        await nextTick();
        nameInput.value?.focus();
    }
});
</script>

<template>
    <form class="product-form" novalidate @submit.prevent="submit">
        <div class="form-field">
            <label :for="ids.name" class="form-field__label">{{ t('productForm.name') }}</label>
            <input
                :id="ids.name"
                ref="nameInput"
                v-model="form.name"
                class="form-field__input"
                type="text"
                name="name"
                autocomplete="off"
                autocapitalize="sentences"
                enterkeyhint="done"
                required
                :maxlength="NAME_MAX"
                :aria-invalid="nameError ? 'true' : 'false'"
                :aria-describedby="nameError ? ids.nameError : ids.nameHint"
            >
            <span v-if="nameError" :id="ids.nameError" class="form-field__error">
                <CircleAlert aria-hidden="true" />
                {{ nameError }}
            </span>
            <span v-else :id="ids.nameHint" class="form-field__hint">{{ t('productForm.nameHint') }}</span>
        </div>

        <section
            v-if="suggestions.length > 0"
            class="product-form__suggestions"
            :aria-labelledby="ids.suggestions"
            aria-live="polite"
        >
            <h3 :id="ids.suggestions" class="product-form__suggestions-title">{{ t('productForm.existing') }}</h3>
            <p class="form-field__hint">{{ t('productForm.existingHint') }}</p>
            <ul class="product-form__suggestion-list">
                <li v-for="item in suggestions" :key="item.id">
                    <button
                        type="button"
                        class="product-form__suggestion"
                        :aria-label="t('productForm.open', { name: item.name })"
                        @click="emit('open', item)"
                    >
                        <ProductImage :image-url="item.imageUrl" :icon="iconOf(item)" />
                        <span class="product-form__suggestion-name">{{ item.name }}</span>
                    </button>
                </li>
            </ul>
        </section>

        <div class="form-field">
            <label :for="ids.packageSize" class="form-field__label">{{ t('productForm.packageSize') }}</label>
            <input
                :id="ids.packageSize"
                v-model="form.packageSize"
                class="form-field__input"
                type="text"
                name="packageSize"
                autocomplete="off"
                :maxlength="SIZE_MAX"
                :placeholder="t('productForm.packageSizePlaceholder')"
            >
        </div>

        <div class="form-field">
            <label :for="ids.category" class="form-field__label">{{ t('productForm.category') }}</label>
            <select :id="ids.category" v-model="form.category" class="form-field__input" name="category">
                <option value="">{{ t('productForm.noCategory') }}</option>
                <option v-for="key in CATEGORIES" :key="key" :value="key">{{ t('categories.' + key) }}</option>
            </select>
        </div>

        <ProductImageField v-model="imageChange" :product="product" :icon="form.icon" />

        <div class="form-field">
            <span class="form-field__label">
                {{ t('productForm.icon') }}
                <span class="form-field__label-hint">· {{ t('productForm.iconHint') }}</span>
            </span>
            <button
                type="button"
                class="product-form__icon"
                :aria-label="t('productForm.icon') + ': ' + t('icons.' + form.icon) + ', ' + t('productForm.changeIcon')"
                aria-haspopup="dialog"
                @click="pickerOpen = true"
            >
                <ProductImage :icon="form.icon" size="tile" />
                <span class="product-form__icon-name">{{ t('icons.' + form.icon) }}</span>
                <span class="product-form__icon-action">{{ t('productForm.changeIcon') }}</span>
            </button>
        </div>

        <div class="form-field">
            <label :for="ids.description" class="form-field__label">
                {{ t('productForm.description') }}
                <span class="form-field__label-hint">· {{ t('productForm.descriptionHint') }}</span>
            </label>
            <textarea
                :id="ids.description"
                v-model="form.description"
                class="form-field__input"
                name="description"
                rows="3"
                :maxlength="DESCRIPTION_MAX"
            ></textarea>
        </div>

        <p v-if="serverError" class="form-field__error" role="alert">
            <CircleAlert aria-hidden="true" />
            {{ serverError }}
        </p>

        <div class="product-form__actions">
            <AppButton variant="secondary" @click="emit('cancel')">{{ t('productForm.cancel') }}</AppButton>
            <AppButton type="submit" :disabled="pending">
                {{ pending ? t('productForm.saving') : t(isNew ? 'productForm.create' : 'productForm.save') }}
            </AppButton>
        </div>

        <IconPicker v-model:open="pickerOpen" v-model="form.icon" />
    </form>
</template>

<style scoped>
.product-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.product-form__suggestions {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-top: calc(-1 * var(--space-2));
    padding: var(--space-3);
    border-radius: var(--radius-md);
    background: var(--color-accent-tint);
}

.product-form__suggestions-title {
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.product-form__suggestion-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
}

.product-form__suggestion {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--tap-size-main);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    background: var(--color-surface-1);
    text-align: left;
}

@media (hover: hover) {
    .product-form__suggestion:hover {
        background: var(--color-surface-2);
    }
}

.product-form__suggestion-name {
    overflow: hidden;
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-medium);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.product-form__icon {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--input-height);
    padding: var(--space-2) var(--space-4) var(--space-2) var(--space-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-1);
    text-align: left;
}

@media (hover: hover) {
    .product-form__icon:hover {
        border-color: var(--color-border-strong);
    }
}

.product-form__icon-name {
    flex: 1;
    font-size: var(--font-size-title);
}

.product-form__icon-action {
    color: var(--color-accent-text);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
}

.product-form__actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
    padding-top: var(--space-2);
}
</style>
