import { Files, LayoutGrid, ListChecks } from '@lucide/vue';

// Hauptziele, in Seitenleiste und mobiler Navigation gleich und in dieser
// Reihenfolge. Profil, Haushalt und Einstellungen liegen im Benutzermenü.
export const navItems = [
    // Einkaufslisten (/lists/…) und das Archiv gehören zu „Listen“
    { to: '/', labelKey: 'nav.lists', icon: ListChecks, exact: true, prefixes: ['/lists', '/archive'] },
    { to: '/products', labelKey: 'nav.products', icon: LayoutGrid },
    { to: '/templates', labelKey: 'nav.templates', icon: Files }
];
