// Deutsch (Hauptsprache und Rückfall). Platzhalter {name}; Mehrzahl als Objekt
// mit den Kategorien von Intl.PluralRules, z. B. { one: '{count} Tag', other: '{count} Tage' }.
export default {
    common: {
        appName: 'Piklis',
        loading: 'Wird geladen …',
        error: 'Etwas ist schiefgelaufen. Bitte noch einmal versuchen.',
        offline: 'Keine Verbindung zum Server.'
    },
    nav: {
        main: 'Hauptnavigation',
        home: 'Start',
        profile: 'Profil'
    },
    login: {
        title: 'Anmelden',
        username: 'Benutzername',
        password: 'Passwort',
        submit: 'Anmelden',
        failed: 'Benutzername oder Passwort ist falsch.'
    },
    home: {
        greeting: 'Hallo {name}!',
        intro: 'Hier beginnt deine App.'
    },
    profile: {
        title: 'Profil',
        theme: 'Darstellung',
        themes: {
            auto: 'Automatisch',
            light: 'Hell',
            dark: 'Dunkel'
        },
        language: 'Sprache',
        languages: {
            auto: 'Automatisch'
        },
        saved: 'Gespeichert',
        logout: 'Abmelden'
    }
};
