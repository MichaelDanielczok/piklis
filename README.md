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
- Fallback icons and category colours: 97 product types in one style (49
  Lucide, 11 from the design package, 37 drawn in the same style; sprite
  from `npm run icons`), ten pastel colour families for surface and line by
  category (`data-category`), overview of all icons at `/#/icons` in the dev
  server
- Suggestion of category and icon from the name (keyword list with word
  stems, longest word end, no AI) when creating, also quick create; the icon
  picker searches keywords too
- Base catalog: 152 common products with category and icon, created once by
  the package setup without creator (only product maintainers edit them),
  existing products are never overwritten
- Product images: take a photo, choose a file or paste a copied image on
  the desktop, square crop in the browser without a library (move, zoom),
  upload of the crop and the original reduced to the project limit, both
  re-encoded (no EXIF/GPS); stored as QUIQQER media (folder
  “Produktbilder”, originals in “Originale”, random UUID names); shown as
  WebP in 128/640/1600 px from the media cache (`quiqqer/cache`); replace,
  re-crop from the original, remove; large view with zoom from the detail
- Shopping lists: overview of the active lists (name, date, origin “nur
  ich” or “Haushalt”, progress, first products), create with a free name and optional
  date, rename, delete; the list in the shop with quantity, unit and note
  per entry (edited in a sheet), new entries in the order they were added,
  sorting only by the drag handle (finger, mouse, arrow keys), checking on
  its own area (the row closes without jumping, toast with “Rückgängig”),
  collapsible “Erledigt” section, unchecked entries return to their old
  place; product details in the list context with quantity and note;
  every change goes through the operations (`POST /ops`), shown at once
- Household (user menu › Haushalt): create, invite code (7 days, reusable,
  renewable by admins, share or copy), join with the code, roles member and
  administrator (appoint, demote, remove members, dissolve; the last admin
  must hand over first), switch to another household, leave and dissolve
  with the offer to keep household lists as personal copies. With a
  household new lists belong to it by default (“Nur für mich” selectable);
  existing personal lists stay private and can be moved or copied into the
  household. Rights are checked on the server for every list route and
  operation
- Sharing single lists: owners of personal lists and household members
  create a one-time link (7 days) and pass it on with the share sheet of the
  device or copy it; the recipient signs in with an existing account (no
  account through the link), accepts, and the list stays in their overview
  with the origin “geteilt”. Guests shop along (add, quantity, note, sort,
  check, remove, complete) but may not share further, rename, delete or
  move the list; participants and open links can be removed or withdrawn,
  guests can leave; used or expired links say “Link nicht mehr gültig,
  bitte um einen neuen”
- Adding products: picker without keyboard on open, two columns of tiles
  (frequently bought first, weighted over 90 days, then the catalog A–Z,
  48 tiles and 24 more while scrolling, order fixed while open), plus
  becomes “− n +” with the plus at the same place, no duplicate entries,
  filter “Nur nicht ausgewählte”, search with “„Begriff“ neu anlegen”
  (product and entry as operations), image or name open the product
- Not got, completion and archive: swipe a row to the left for “Nicht
  bekommen” (own section, symbol and text, undo in the toast); take not-got
  entries over into another active or a new list, one by one or “Alle
  übernehmen …” (`entry.takeOver`: same unit adds up, another unit asks
  keep · replace · both), the origin keeps them as “nicht bekommen · nach
  REWE”; “Einkauf abschließen” offers to take over open and not-got
  entries, the list goes into the archive (reachable from the overview),
  stays readable and can be reopened; a new list starts empty or from an
  earlier purchase with quantities and order, products can be deselected
- Live updates of shared lists: the open list polls “since N” every 5 s,
  the overview `GET /lists` every 30 s, only while the app is visible, with
  ETag/304; changes of others appear without reloading and without jumps
  (new rows open, checked rows close, moved rows glide), a drag in progress
  is not disturbed (changes appear on release); removal from the household
  or its dissolution reaches the overview at the next poll. Real conflicts
  (different quantities or notes, an old check, a change to a removed
  entry) ask in a sheet; nothing is silently overwritten
- REST API `/api/piklis/v1` (`GET /me`, `PATCH /me/settings`, `GET /config`,
  `POST /ops`, `GET /lists`, `GET /lists/{id}`, household routes, list
  move/copy, `POST /lists/{id}/invites`, `GET /lists/{id}/shares`,
  `GET /invites/{token}`, `POST /invites/{token}/accept`, `GET|POST /products`,
  `GET /products/search`, `GET /products/suggestions`, `GET|PATCH /products/{id}`,
  `POST /products/{id}/hide|unhide`, `POST|DELETE /products/{id}/image`)
- PHPStan level 8, PHPCS, PHPUnit; JS tests with `node --test`

## Quick start

```bash
npm install
npm run dev     # Vite, proxies the API to APP_BACKEND
npm run build   # before every commit; bin/app/ is committed
npm run icons   # after changing data/icons.json or build/icons/*.svg
npm test        # JS tests (node --test, shared fixtures with PHPUnit)
```

## Documentation

- [Architecture](docs/architecture.md): structure, design, components,
  data model, sync and conflict rules, product catalog, product images,
  PWA, REST, login, texts
- [Development](docs/development.md): instance, mock backend, Vite, tools

## Third-party licences

The fallback icon sprite contains icons from [Lucide](https://lucide.dev)
(ISC licence, see `LICENSE-lucide.txt`).
