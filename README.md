# Piklis

Private shopping list app for the household: an installable PWA (phone
first, desktop usable) on QUIQQER. Vue 3 + Ionic app as standalone start
page of its own QUIQQER instance, talking to a `quiqqer/rest` API with the
QUIQQER session. German only.

Built on the template `quiqqer-pwa-starter`. Concept, decisions and roadmap
live in Obsidian (“Piklis”, “Piklis Konzept”, “Piklis Entscheidungen”,
“Piklis Roadmap”); the design package “Leinen” lies in the instance root
under `design/piklis-designpaket/`.

## What is included

- Login with QUIQQER users (core login, own form), session check, 401 handling
- Navigation Listen · Produkte · Vorlagen: floating glass bar on phones,
  sidebar on desktop; placeholder views
- User menu at the avatar: Profil, Haushalt, Einstellungen, Darstellung
  (theme automatic/light/dark), sign out
- Design “Leinen”: tokens light/dark, system font, app icon, favicons,
  boot loader, toast, sheet
- Base components (product image, list row with its states, quantity, add
  tile, section label, sheet, skeleton row) with a pattern page in the dev
  server (`/#/components`)
- Permission `piklis.products.manage` for product maintainers
- PWA: manifest, service worker (precache, start page stale-while-revalidate)
- REST API `/api/piklis/v1` (`GET /me`, `PATCH /me/settings`)
- PHPStan level 8, PHPCS, PHPUnit

## Quick start

```bash
npm install
npm run dev     # Vite, proxies the API to APP_BACKEND
npm run build   # before every commit; bin/app/ is committed
```

## Documentation

- [Architecture](docs/architecture.md): structure, design, components,
  PWA, REST, login, texts
- [Development](docs/development.md): instance, mock backend, Vite, tools
