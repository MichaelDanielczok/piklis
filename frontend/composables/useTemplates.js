/**
 * Vorlagen (Konzept „Vorlagen“, docs/architecture.md „Templates“).
 *
 * Anders als Listen nur online und immer ganz gespeichert: Wer bearbeitet,
 * arbeitet an einem Entwurf (Name und alle Produkte mit Menge, Notiz und
 * Reihenfolge); „Speichern“ schickt ihn mit der Revision, von der er
 * ausging (`If-Match`). Hat inzwischen jemand anderes gespeichert, kommt
 * `template_changed` mit dem aktuellen Stand zurück, und die Ansicht fragt,
 * was gelten soll. Kein Live-Abgleich: die Übersicht und eine Vorlage laden
 * beim Öffnen.
 *
 * - Übersicht: `GET /templates` (Anzahl, Vorschau).
 * - Eine Vorlage: `GET /templates/{id}`.
 * - Entwurf je Vorlage im Speicher der App, damit „Produkte hinzufügen“ und
 *   das Produkt dazwischen ihn nicht verlieren; Abbrechen verwirft ihn.
 * - Neue Liste aus einer Vorlage legt `useLists` an (Operationen wie beim
 *   früheren Einkauf); die Liste ist unabhängig von der Vorlage.
 */
import { reactive, shallowRef, watch } from 'vue';
import { api, logError } from '@/api/index.js';
import { onLogout, useSession } from './useSession.js';
import { draftOf, isDraftChanged, moveItem, templateEntries, withQuantity } from '@/utils/templates.js';

/** Übersicht: zugängliche Vorlagen mit `role`, `count`, `preview`. */
const overview = reactive({
    status: 'idle', // idle · loading · ready · error
    templates: []
});

/**
 * Geladene Vorlagen: id → { status, template, reason }.
 * status: loading · ready · error · missing (`template_deleted`,
 * `template_not_found`, `no_access`).
 */
const details = shallowRef({});

/** Entwürfe: id → { name, revision, items }. */
const drafts = shallowRef({});

const { household } = useSession();

function setDetail(templateId, detail) {
    details.value = { ...details.value, [templateId]: Object.freeze(detail) };
}

function setDraft(templateId, draft) {
    const next = { ...drafts.value };

    if (draft) {
        next[templateId] = Object.freeze(draft);
    } else {
        delete next[templateId];
    }

    drafts.value = next;
}

function summaryOf(template) {
    return {
        id: template.id,
        name: template.name,
        ownerUser: template.ownerUser,
        householdId: template.householdId,
        revision: template.revision,
        createdBy: template.createdBy,
        createdAt: template.createdAt,
        updatedBy: template.updatedBy,
        updatedAt: template.updatedAt,
        role: template.role,
        count: template.entries.length,
        preview: template.entries.slice(0, 4).map((entry) => entry.productId)
    };
}

function sortOverview(templates) {
    return [...templates].sort((a, b) => a.name.localeCompare(b.name, 'de') || a.id.localeCompare(b.id));
}

/** Vorlage in Übersicht und Detail übernehmen (Antwort des Servers). */
function remember(template) {
    setDetail(template.id, { status: 'ready', template, reason: null });

    const summary = summaryOf(template);
    const others = overview.templates.filter((item) => item.id !== template.id);

    overview.templates = sortOverview([...others, summary]);
}

function forget(templateId, reason) {
    overview.templates = overview.templates.filter((item) => item.id !== templateId);
    setDetail(templateId, { status: 'missing', template: null, reason });
    setDraft(templateId, null);
}

onLogout(() => {
    overview.status = 'idle';
    overview.templates = [];
    details.value = {};
    drafts.value = {};
});

// anderer Haushalt: Haushaltsvorlagen gelten nicht mehr bzw. neu
watch(() => household.value?.id ?? null, () => {
    details.value = {};

    if (overview.status !== 'idle') {
        loadTemplates().catch(logError);
    }
});

export async function loadTemplates() {
    if (overview.status !== 'ready') {
        overview.status = 'loading';
    }

    try {
        const data = await api.getTemplates();
        const templates = data.templates ?? [];

        if (JSON.stringify(templates) !== JSON.stringify(overview.templates)) {
            overview.templates = templates;
        }

        overview.status = 'ready';
    } catch (error) {
        if (overview.status !== 'ready') {
            overview.status = 'error';
        }

        throw error;
    }
}

export async function loadTemplate(templateId) {
    const known = details.value[templateId];

    if (known?.status !== 'ready') {
        setDetail(templateId, { status: 'loading', template: null, reason: null });
    }

    try {
        const { template } = await api.getTemplate(templateId);

        remember(template);

        return template;
    } catch (error) {
        if (['template_deleted', 'template_not_found', 'no_access'].includes(error?.code)) {
            forget(templateId, error.code);
            return null;
        }

        if (known?.status !== 'ready') {
            setDetail(templateId, { status: 'error', template: null, reason: null });
        }

        throw error;
    }
}

/** Vorlage laden, falls sie noch fehlt. */
export async function ensureTemplateLoaded(templateId) {
    const detail = details.value[templateId];

    if (detail?.status === 'ready') {
        return detail.template;
    }

    return loadTemplate(templateId);
}

/**
 * Neue Vorlage (aus einer Liste oder leer), persönlich oder im Haushalt.
 *
 * @param {{ name: string, householdId?: string|null, items?: object[], withNotes?: boolean }} fields
 * @returns {Promise<object>} die gespeicherte Vorlage
 */
export async function createTemplate({ name, householdId = null, items = [], withNotes = false }) {
    const { template } = await api.createTemplate({
        id: crypto.randomUUID(),
        name,
        householdId,
        entries: templateEntries(items, { withNotes })
    });

    remember(template);

    return template;
}

/** Entwurf einer Vorlage (null ohne). */
export function draftFor(templateId) {
    return drafts.value[templateId] ?? null;
}

/** Bearbeiten beginnen (oder den vorhandenen Entwurf weiterführen). */
export function startEditing(templateId) {
    const existing = draftFor(templateId);
    const template = details.value[templateId]?.template;

    if (existing || !template) {
        return existing;
    }

    const draft = draftOf(template);

    setDraft(templateId, draft);

    return draft;
}

export function discardDraft(templateId) {
    setDraft(templateId, null);
}

/** Hat der Entwurf ungespeicherte Änderungen? */
export function isTemplateChanged(templateId) {
    return isDraftChanged(draftFor(templateId), details.value[templateId]?.template);
}

function patchDraft(templateId, change) {
    const draft = draftFor(templateId);

    if (draft) {
        setDraft(templateId, { ...draft, ...change(draft) });
    }
}

export function renameDraft(templateId, name) {
    patchDraft(templateId, () => ({ name }));
}

/** Menge im Entwurf setzen (0 nimmt das Produkt heraus), für „Produkte hinzufügen“. */
export function setDraftQuantity(templateId, productId, amount) {
    patchDraft(templateId, (draft) => ({ items: withQuantity(draft.items, productId, amount) }));
}

/** Menge (mit Einheit) und/oder Notiz eines Produkts im Entwurf ändern. */
export function updateDraftItem(templateId, productId, changes) {
    patchDraft(templateId, (draft) => ({
        items: draft.items.map((item) => (item.productId === productId ? { ...item, ...changes } : item))
    }));
}

export function removeDraftItem(templateId, productId) {
    patchDraft(templateId, (draft) => ({ items: draft.items.filter((item) => item.productId !== productId) }));
}

/** Produkt an einer bestimmten Stelle wieder einsetzen („Rückgängig“). */
export function insertDraftItem(templateId, item, index) {
    patchDraft(templateId, (draft) => {
        const items = draft.items.filter((other) => other.productId !== item.productId);

        items.splice(Math.min(index, items.length), 0, item);

        return { items };
    });
}

export function moveDraftItem(templateId, from, to) {
    patchDraft(templateId, (draft) => ({ items: moveItem(draft.items, from, to) }));
}

/**
 * Entwurf speichern. Mit `revision` auf diesen Stand speichern („Meine
 * Fassung speichern“, nachdem jemand anderes inzwischen gespeichert hat).
 *
 * @returns {Promise<{ status: 'saved', template: object } | { status: 'changed', current: object }>}
 */
export async function saveDraft(templateId, { revision = null } = {}) {
    const draft = draftFor(templateId);

    if (!draft) {
        return { status: 'saved', template: details.value[templateId]?.template ?? null };
    }

    try {
        const { template } = await api.saveTemplate(templateId, revision ?? draft.revision, {
            name: draft.name.trim(),
            entries: draft.items
        });

        setDraft(templateId, null);
        remember(template);

        return { status: 'saved', template };
    } catch (error) {
        if (error?.code === 'template_changed' && error.data?.template) {
            return { status: 'changed', current: error.data.template };
        }

        if (['template_deleted', 'template_not_found', 'no_access'].includes(error?.code)) {
            forget(templateId, error.code);
        }

        throw error;
    }
}

/** Den inzwischen gespeicherten Stand übernehmen und den eigenen Entwurf verwerfen. */
export function takeCurrent(templateId, current) {
    setDraft(templateId, null);
    remember(current);
}

/** Nur den Namen ändern (Sheet „Vorlage bearbeiten“), auf Basis des geladenen Stands. */
export async function renameTemplate(templateId, name) {
    const template = details.value[templateId]?.template;

    if (!template) {
        return null;
    }

    const { template: saved } = await api.saveTemplate(templateId, template.revision, {
        name,
        entries: templateEntries(template.entries)
    });

    remember(saved);

    return saved;
}

export async function deleteTemplate(templateId) {
    await api.deleteTemplate(templateId);
    forget(templateId, 'template_deleted');
}

export async function moveTemplateToHousehold(templateId) {
    const { template } = await api.moveTemplateToHousehold(templateId);
    const draft = draftFor(templateId);

    remember(template);

    // Verschieben zählt die Revision hoch; ein Entwurf auf dem Stand davor bleibt gültig
    if (draft && draft.revision === template.revision - 1) {
        setDraft(templateId, { ...draft, revision: template.revision });
    }

    return template;
}

/** Kopie in den Haushalt ('household') oder als persönliche Kopie ('personal'). */
export async function copyTemplateTo(templateId, to) {
    const { template } = await api.copyTemplate(templateId, crypto.randomUUID(), to);

    remember(template);

    return template;
}

/** Zustand einer Vorlage für die Ansicht. */
export function templateDetail(templateId) {
    return details.value[templateId] ?? { status: 'idle', template: null, reason: null };
}

export function useTemplates() {
    return {
        overview,
        details,
        drafts
    };
}
