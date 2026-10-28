/**
 * Haushalt des Benutzers (Ansicht „Haushalt“): geladen über `GET /household`,
 * Aktionen über die REST-Routen. Jede Antwort ist der neue Stand.
 *
 * Ändert sich die Mitgliedschaft (anlegen, beitreten, wechseln, austreten,
 * auflösen), bekommt die Session die Kurzform (Standard für neue Listen) und
 * die Listen werden neu geladen: Haushaltslisten kommen dazu oder fallen weg.
 * Ändert sie jemand anderes (entfernt, aufgelöst), merkt es der Live-Abgleich
 * der Übersicht (`GET /lists` liefert die Kurzform mit).
 */
import { ref } from 'vue';
import { api } from '@/api/index.js';
import { onLogout, setSessionHousehold } from './useSession.js';
import { onHouseholdChange, reloadAfterHouseholdChange } from './useLists.js';

/** undefined: noch nicht geladen · null: kein Haushalt · sonst { id, name, role, members, invite } */
const household = ref(undefined);
const status = ref('idle'); // idle · loading · ready · error

onLogout(() => {
    household.value = undefined;
    status.value = 'idle';
});

// Die Übersicht fragt regelmäßig ab und meldet, wenn sich der Haushalt
// geändert hat (entfernt, aufgelöst, Rolle): geladenen Stand neu holen
onHouseholdChange(() => {
    if (household.value !== undefined) {
        loadHousehold().catch((error) => console.error(error));
    }
});

function apply(next, { membershipChanged = false } = {}) {
    const before = household.value?.id ?? null;

    household.value = next ?? null;
    setSessionHousehold(household.value);

    if (membershipChanged || before !== (household.value?.id ?? null)) {
        reloadAfterHouseholdChange().catch((error) => console.error(error));
    }

    return household.value;
}

export async function loadHousehold() {
    if (status.value !== 'ready') {
        status.value = 'loading';
    }

    try {
        const data = await api.getHousehold();

        apply(data.household);
        status.value = 'ready';
    } catch (error) {
        if (status.value !== 'ready') {
            status.value = 'error';
        }

        throw error;
    }
}

export async function createHousehold(name) {
    return apply((await api.createHousehold(name)).household, { membershipChanged: true });
}

/**
 * Mit Code beitreten; `switchHousehold` verlässt dabei den bisherigen
 * Haushalt, `confirmDissolve` löst ihn als letztes Mitglied auf.
 */
export async function joinHousehold(code, options = {}) {
    return apply((await api.joinHousehold(code, options)).household, { membershipChanged: true });
}

export async function renewInvite() {
    return apply((await api.renewHouseholdInvite()).household);
}

export async function leaveHousehold(options = {}) {
    return apply((await api.leaveHousehold(options)).household, { membershipChanged: true });
}

export async function dissolveHousehold() {
    return apply((await api.dissolveHousehold()).household, { membershipChanged: true });
}

export async function setMemberRole(userId, role) {
    return apply((await api.setHouseholdRole(userId, role)).household);
}

export async function removeMember(userId) {
    return apply((await api.removeHouseholdMember(userId)).household);
}

/**
 * Darf der Benutzer gehen? `blocked`: letzter Administrator neben anderen
 * Mitgliedern (erst übergeben). `lastMember`: Gehen löscht den Haushalt.
 */
export function leaveSituation(value) {
    const members = value?.members ?? [];
    const admins = members.filter((member) => member.role === 'admin').length;

    return {
        lastMember: members.length <= 1,
        blocked: members.length > 1 && value?.role === 'admin' && admins <= 1
    };
}

export function useHousehold() {
    return { household, status };
}
