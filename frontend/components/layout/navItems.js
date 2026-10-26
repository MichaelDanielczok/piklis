import { House, UserRound } from '@lucide/vue';

// Navigationsziele, in Seitenleiste und mobiler Navigation gleich und in dieser
// Reihenfolge. Das Profil steht in der Seitenleiste immer unten.
export const navItems = [
    { to: '/', labelKey: 'nav.home', icon: House, exact: true },
    { to: '/profile', labelKey: 'nav.profile', icon: UserRound }
];
