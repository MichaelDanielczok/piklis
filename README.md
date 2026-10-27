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
- Data model and sync: lists, entries and change log in the database;
  all changes as operations with ID (`POST /ops`), revision per list,
  “all since revision N” (`GET /lists/{id}?since=N`), conflict rules per
  field; sort keys between neighbours and quantities with the same logic
  in PHP and JS; operation queue in the frontend (`api/operations.js`)
- Product catalog: search with suggestions while typing, list with image
  or icon, detail, create (only the name is required) and edit, searchable
  icon picker by category; product maintenance in two columns on desktop;
  rights: everybody creates and edits their own products, maintainers edit
  all and hide them
- Product images: take a photo, choose a file or paste a copied image on
  the desktop, square crop in the browser without a library (move, zoom),
  upload of the crop and the original reduced to the project limit, both
  re-encoded (no EXIF/GPS); stored as QUIQQER media (folder
  “Produktbilder”, originals in “Originale”, random UUID names); shown as
  WebP in 128/640/1600 px from the media cache (`quiqqer/cache`); replace,
  re-crop from the original, remove; large view with zoom from the detail
- REST API `/api/piklis/v1` (`GET /me`, `PATCH /me/settings`, `GET /config`,
  `POST /ops`, `GET /lists`, `GET /lists/{id}`, `GET|POST /products`,
  `GET /products/search`, `GET|PATCH /products/{id}`,
  `POST /products/{id}/hide|unhide`, `POST|DELETE /products/{id}/image`)
- PHPStan level 8, PHPCS, PHPUnit; JS tests with `node --test`

## Quick start

```bash
npm install
npm run dev     # Vite, proxies the API to APP_BACKEND
npm run build   # before every commit; bin/app/ is committed
npm test        # JS tests (node --test, shared fixtures with PHPUnit)
```

## Documentation

- [Architecture](docs/architecture.md): structure, design, components,
  data model, sync and conflict rules, product catalog, product images,
  PWA, REST, login, texts
- [Development](docs/development.md): instance, mock backend, Vite, tools
