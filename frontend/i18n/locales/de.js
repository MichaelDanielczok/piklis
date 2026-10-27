// Deutsch (einzige Sprache und Rückfall). Platzhalter {name}; Mehrzahl als Objekt
// mit den Kategorien von Intl.PluralRules, z. B. { one: '{count} Tag', other: '{count} Tage' }.
export default {
    common: {
        appName: 'Piklis',
        loading: 'Wird geladen …',
        error: 'Etwas ist schiefgelaufen. Bitte noch einmal versuchen.',
        offline: 'Keine Verbindung zum Server.',
        back: 'Zurück',
        close: 'Schließen',
        undo: 'Rückgängig',
        soon: 'Kommt in einem der nächsten Schritte.'
    },
    nav: {
        main: 'Hauptnavigation',
        lists: 'Listen',
        products: 'Produkte',
        templates: 'Vorlagen'
    },
    menu: {
        open: 'Benutzermenü von {name}',
        label: 'Benutzermenü',
        profile: 'Profil',
        household: 'Haushalt',
        settings: 'Einstellungen',
        theme: 'Darstellung',
        components: 'Musterseite',
        logout: 'Abmelden',
        noHousehold: 'Ohne Haushalt'
    },
    theme: {
        auto: 'Automatisch',
        light: 'Hell',
        dark: 'Dunkel',
        saved: 'Darstellung gespeichert'
    },
    login: {
        title: 'Anmelden',
        username: 'Benutzername',
        password: 'Passwort',
        submit: 'Anmelden',
        failed: 'Benutzername oder Passwort ist falsch.'
    },
    lists: {
        title: 'Listen',
        emptyTitle: 'Noch keine Einkaufslisten',
        emptyText: 'Hier stehen bald deine Listen, die Listen deines Haushalts und Listen, die andere mit dir teilen.'
    },
    products: {
        title: 'Produkte',
        emptyTitle: 'Der Produktkatalog entsteht',
        emptyText: 'Hier findest du bald alle Produkte mit Bild, Beschreibung und Kategorie.'
    },
    templates: {
        title: 'Vorlagen',
        emptyTitle: 'Noch keine Vorlagen',
        emptyText: 'Aus Vorlagen startest du später wiederkehrende Einkäufe mit einem Tipp.'
    },
    profile: {
        title: 'Profil',
        nameHint: 'Name und Bild pflegt der Administrator im QUIQQER-Backend.',
        productManager: 'Produktpfleger: darf alle Produkte bearbeiten',
        logout: 'Abmelden'
    },
    household: {
        title: 'Haushalt',
        emptyTitle: 'Noch kein Haushalt',
        emptyText: 'Mit einem Haushalt teilst du Listen und Vorlagen mit deiner Familie.'
    },
    settings: {
        title: 'Einstellungen',
        theme: 'Darstellung',
        themeHint: 'Automatisch folgt der Einstellung des Geräts.'
    },
    // Mengen: Zahl plus optionale Einheit; ohne Einheit „2 ד
    units: {
        none: '{count} ×',
        piece: '{count} Stück',
        pack: { one: '{count} Packung', other: '{count} Packungen' },
        bottle: { one: '{count} Flasche', other: '{count} Flaschen' },
        cup: '{count} Becher',
        can: { one: '{count} Dose', other: '{count} Dosen' },
        jar: { one: '{count} Glas', other: '{count} Gläser' },
        bag: '{count} Beutel',
        net: { one: '{count} Netz', other: '{count} Netze' },
        bunch: '{count} Bund',
        roll: { one: '{count} Rolle', other: '{count} Rollen' },
        bar: { one: '{count} Tafel', other: '{count} Tafeln' },
        g: '{count} g',
        kg: '{count} kg',
        ml: '{count} ml',
        l: '{count} l'
    },
    unitNames: {
        piece: 'Stück',
        pack: 'Packung',
        bottle: 'Flasche',
        cup: 'Becher',
        can: 'Dose',
        jar: 'Glas',
        bag: 'Beutel',
        net: 'Netz',
        bunch: 'Bund',
        roll: 'Rolle',
        bar: 'Tafel',
        g: 'g',
        kg: 'kg',
        ml: 'ml',
        l: 'l'
    },
    row: {
        handle: '„{name}“ verschieben',
        check: '„{name}“ abhaken',
        uncheck: '„{name}“ wieder offen',
        transfer: '„{name}“ in eine andere Liste übernehmen',
        done: 'erledigt',
        notGot: 'nicht bekommen',
        unsynced: 'noch nicht übertragen',
        checked: '„{name}“ erledigt'
    },
    tile: {
        add: '„{name}“ hinzufügen',
        increase: '„{name}“: eins mehr',
        decrease: '„{name}“: eins weniger',
        remove: '„{name}“ entfernen',
        selected: 'ausgewählt'
    },
    section: {
        count: '{label} · {count}'
    },
    components: {
        title: 'Musterseite',
        intro: 'Grundbausteine von Piklis. Nur im Entwicklungsmodus erreichbar.',
        productImage: 'Produktbild',
        productImageHint: 'Foto oder Ersatzicon, gleiche Größe und Rundung',
        icons: 'Ersatzicons',
        rows: 'Listenzeilen',
        quantities: 'Mengen',
        tiles: 'Hinzufügen-Kacheln',
        labels: 'Abschnittslabel',
        sheet: 'Sheet',
        openSheet: 'Sheet öffnen',
        sheetTitle: 'Beispiel-Sheet',
        sheetText: 'Sheets kommen von unten, mit Griffmarke und Schließen-Knopf. Wischen nach unten oder Tippen daneben schließt.',
        skeleton: 'Ladezustand',
        toast: 'Toast',
        showToast: 'Toast zeigen',
        toastUndone: 'Rückgängig gemacht',
        sectionOpen: 'Offen',
        sectionNotGot: 'Nicht bekommen',
        sectionDone: 'Erledigt',
        sampleNote: 'die mit dem blauen Deckel',
        sampleNote2: 'diesmal für den Kuchen'
    }
};
