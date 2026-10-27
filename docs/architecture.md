# Architecture

## Overview

- Piklis: private shopping list app as installable PWA, phone first, desktop
  usable. German only (texts still through `t()`).
- QUIQQER package `michaeldanielczok/piklis`, namespace
  `MichaelDanielczok\Piklis`, app id `pk` (user attributes, `localStorage`,
  console commands). One QUIQQER instance per app. No core
  changes; everything lives in this package.
- The app is the start page `/` of the instance: a
  `quiqqer/sitetypes:types/standalone` page without template. Its content
  field holds `standalone.html` (mount point `#app`, boot loader, manifest,
  favicon, `theme-color`, `bin/app/app.css` and `bin/app/app.js`).
- Frontend: Vue 3, Ionic Vue (`mode: 'ios'`), vue-router (hash mode),
  `@lucide/vue` for icons. No Pinia, state lives in composables. No
  Tailwind, no i18n library.
- Backend: `quiqqer/rest` under `/api/piklis/v1`, authenticated by the
  QUIQQER session.

## Package files

| File | Purpose |
| --- | --- |
| `package.xml` | registers the REST provider |
| `database.xml` | own tables, prefix `pk_`, portable types (see [Data model](#data-model)) |
| `permissions.xml` | `piklis.products.manage`: product maintainers may edit all products (sent as `permissions.manageProducts` in `GET /me`) |
| `user.xml` | app settings as user attributes (`pk.theme`) |
| `events.xml` | `onRequest`: service worker and manifest |
| `console.xml`, `cron.xml` | example command `pk:hello`, the same code as cron (created inactive) |
| `locale.xml`, `locale/*.xml` | backend texts (see [Texts](#texts)) |
| `standalone.html` | content of the start page |

PHP under `src/MichaelDanielczok/Piklis/`, grouped by area: `Rest/`
(endpoints), `Sync/` (operations, conflict rules, without database),
`Lists/` (sort keys, quantities, `ListRepository`), `Database/` (table
names), `Users/`, `Pwa/`, `Console/`.

## Frontend

Sources under `frontend/`, built by Vite into `bin/app/`. The build is
committed because the server does not build (no Node there).

| Path | Content |
| --- | --- |
| `app.js`, `App.vue`, `router.js` | entry, shell, routes |
| `api/index.js` | the only module that talks to the server (REST and core Ajax) |
| `api/operations.js` | operations: builders, queue, sending, pull since revision (see [Sync](#sync)) |
| `composables/` | shared state: session, toast, page transition |
| `components/layout/` | page frame and header, sidebar, mobile navigation, nav items, user menu |
| `components/ui/` | button, sheet, section label, theme switch, avatar, logo, empty state |
| `components/list/` | list row, row group, skeleton row, quantity, add tile |
| `components/products/` | product image (photo or fallback icon), fallback icon |
| `icons/` | fallback icon sprite (`ersatzicons.svg`, embedded once at start) |
| `utils/quantity.js` | quantities with unit (`2 ×`, `2 Packungen`, `1,5 kg`), input with comma, checks |
| `utils/sortKey.js` | sort keys between two neighbours (same logic as `Lists\SortKey`) |
| `views/` | one view per route |
| `styles/tokens.css` | all colours, sizes and spacing as CSS variables |
| `i18n/` | `t()`, texts in `locales/de.js`, `Intl` for numbers, dates and plurals |
| `public/icons/` | PWA icons and favicons (from `build/*.svg`) |
| `dev/samples/` | sample photos for the pattern page, dev server only |

- Routes (hash): `/login` (`meta.public`); navigation `/` (Listen),
  `/products` (Produkte), `/templates` (Vorlagen); from the user menu
  `/profile`, `/household`, `/settings`; `/components` (pattern page) only in
  the dev server (`import.meta.env.DEV`, missing in the build). Everything
  except public routes requires a session; `meta.manageProducts`
  additionally requires `piklis.products.manage`. A `401` from the API
  returns to login with `?redirect=`.
- Language: German only, no language setting. Theme: `auto | light | dark`
  (default `auto`, follows the system) via `data-theme` on `<html>`, chosen
  in the user menu or in Einstellungen; `applyTheme()` also sets both
  `theme-color` meta tags and remembers the theme for the login page.
- Logout clears module state: other composables register a reset with
  `onLogout()` from `useSession.js`.

## Layout and design

Design direction “Leinen” from the design package in the instance root
(`design/piklis-designpaket/`: `DESIGN.md`, tokens, logo, fallback icons,
screenshots). It sets the direction, not pixels: values come from its
tokens, rounded to the grid below and stored under the starter's token
names in `styles/tokens.css` (no second token system). Where the design
package and the concept (Obsidian “Piklis Konzept”) disagree, the concept
wins.

- **Grid:** spacing in quarter rem, radii in eighth rem, font sizes in
  sixteenth rem, breakpoints in `em` (`45em` tablet, `64em` desktop with
  sidebar). Components only use the variables from `tokens.css`.
- **Colours:** warm neutrals and one brand colour (green `#1F6E4E`, dark
  `#5CC495`). Light is the default, dark follows the system or
  `data-theme="dark"`. Status colours `--color-done`, `--color-notgot`,
  `--color-unsynced` always come with a symbol and text, never colour alone.
- **Font:** system font only (SF Pro, Roboto, Segoe UI), no web font.
- **Shell** (`App.vue`): sidebar from `64em`, else the floating glass bar at
  the bottom.
- **Header** (`AppHeader.vue`, rendered by `AppPage.vue`): large title
  (28 px) with optional subtitle, back button on sub pages (`back`), actions
  slot, avatar with user menu on phones. It scrolls with the content; the
  glass strip in `AppPage.vue` covers the status bar of the installed app.
  `AppPage` `width`: `wide` · `list` · `narrow`.
- **Navigation:** Listen · Produkte · Vorlagen (`navItems.js`).
  `MobileNav.vue`: the starter's floating glass bar in Leinen colours, active
  item with a green pill behind the icon and green label. `SideNav.vue`: app
  icon and name, items with a green pill, user menu at the bottom.
- **User menu** (`UserMenu.vue`, `placement` `header` | `sidebar`): name,
  Profil, Haushalt, Einstellungen, Darstellung (theme switch directly in the
  menu as `menuitemradio`), Musterseite (dev server only), Abmelden. Menu
  button keyboard pattern (arrows, Home/End, Escape).
- **Toast** (`useToast.js`, style in `base.css`): dark surface, symbol on the
  left (`icon: 'done' | 'notgot' | 'info'`), green action (“Rückgängig”), 5 s,
  above the floating bar.
- **Sheets** (`AppSheet.vue`): Ionic modal as sheet with automatic height,
  radius 22 px at the top, handle, title and subtitle, round close button,
  scrim from the tokens; centred from `45em`.
- **App icon:** “Zettelstapel” from the design package. `build/icon.svg`
  (rounded, manifest `any`), `build/icon-maskable.svg` (full bleed, maskable
  and Apple touch icon), `build/favicon.svg` (reduced, browser tab); render
  with `build/make-icons.sh`. Manifest and `theme-color` use the page
  background (`#F7F6F3` light, `#141615` dark), not the green of the design
  package's example manifest: a green status bar above the light page looked
  wrong. Boot loader in `standalone.html`: app icon and progress bar.
- Hover styles only inside `@media (hover: hover)` (sticky hover on iOS).
- Ionic's swipe-back gesture is off; a browser back (Safari gesture)
  switches pages without animation (`usePageTransition.js`). Re-enable the
  gesture in a native shell.

## Base components

All shown on the pattern page `/#/components` (dev server only), with sample
data and sample photos cut from the design package's drawn placeholders.

| Component | Purpose |
| --- | --- |
| `products/ProductImage.vue` | photo or fallback icon with the same size and radius (`row` 44 px, `tile` 48 px, `lg` 80 px); `muted` for done rows; a broken photo falls back to the icon |
| `products/ProductIcon.vue` | one fallback icon from the sprite (`#pk-<name>`, unknown names show `allgemein`) |
| `list/ListRow.vue` | list row with three separate touch areas: handle (32 px wide, full row height), details (image, name max. 2 lines, note or status, quantity), check zone (60 px wide, full row height, ring 28 px). States `open` · `done` · `notGot` (transfer button instead of the ring), plus `unsynced` |
| `list/RowGroup.vue` | card for rows, separators instead of single cards |
| `list/SkeletonRow.vue` | loading row with exactly the end height (64 px) and the same zones, flat without shimmer |
| `list/QuantityLabel.vue` | quantity, semibold with tabular figures |
| `list/AddTile.vue` | tile in “Produkte hinzufügen”: fixed height 124 px, plus button turns into “− 1 +” with the plus at the same place, inset outline when selected, minus at 1 removes |
| `ui/SectionLabel.vue` | section heading in capitals (“ERLEDIGT · 8”), capitals by CSS only |
| `ui/AppSheet.vue` | bottom sheet (see above) |
| `ui/AppButton.vue`, `ui/ThemeSwitch.vue`, `ui/UserAvatar.vue`, `ui/AppLogo.vue`, `ui/EmptyState.vue` | button, theme choice, avatar or initials, app icon with name, placeholder of a view |

Deviations from the design package, chosen for the phone:

- Drag handle 32 px wide over the full row height (design: 28 × 48 or
  24 × 44): easier to hit, on the 4 px grid.
- Check zone 60 px wide over the full row height (design: 60 × 64 or
  48 × 48): the larger value of the package.
- A “not got” row shows a round transfer button (arrow) in the check zone.
- A status without note (“noch nicht übertragen”) may wrap to a second line
  instead of being cut off.

Quantities (`utils/quantity.js`): number plus optional unit from a fixed list;
rules in [Quantities](#quantities). Texts and plurals in `i18n` (`units.*`,
`unitNames.*`).

Fallback icons: the 16 icons of the design package (24 grid, line 1.75,
round caps, `currentColor`) as sprite in `icons/ersatzicons.svg`, embedded
once into the document (`installIconSprite()` in `app.js`), shown with
`<use href="#pk-milch">`. More icons follow (Lucide and own ones in the same
style).

## Texts

- App texts in `frontend/i18n/locales/de.js` (nested objects,
  `t('menu.profile')`). They are part of `app.js`: no request at start,
  available offline. German is the only language for now; there is no
  language setting and no detection of the device language.
- `locale.xml` with `locale/de.xml` holds only backend texts (permissions,
  cron, settings); PHP never needs the app texts.
- Placeholders: `{name}`, numbers formatted per language. Plurals as object
  with the categories of `Intl.PluralRules`; `t('<key>', { count })` picks
  the form:

  ```js
  pack: { one: '{count} Packung', other: '{count} Packungen' }        // de
  pack: { one: '{count} opakowanie', few: '{count} opakowania', many: '{count} opakowań', other: '{count} opakowania' } // pl
  ```

- Missing keys fall back to `de` (`FALLBACK_LOCALE`), unknown keys show the
  key itself.

Add a language later, e.g. Polish:

1. `frontend/i18n/locales/pl.js`, same keys as `de.js`.
2. `frontend/i18n/index.js`: import it, add to `messages`, bring back a
   language choice (user attribute, `Users\Profile`, settings view).
3. Backend texts: `locale/pl.xml` and an entry in `locale.xml`; activate the
   language in the QUIQQER translator and project.

## PWA

- `vite-plugin-pwa` (`generateSW`) writes `sw.js`, `manifest.webmanifest`
  and icons into `bin/app/`. Registration in `frontend/pwa.js`, build only.
- A service worker only controls paths below its own location, so
  `Pwa\EventHandler::onRequest` serves `/sw.js` and `/manifest.webmanifest`
  from `bin/app/` with `Cache-Control: no-cache`. No web server rule needed.
  All URLs in both files are absolute (`/packages/<vendor>/<package>/bin/app/…`),
  the Workbox runtime is inlined.
- Precache: all `js`, `css`, fonts and icons. The start page `/` uses
  `StaleWhileRevalidate` (otherwise the installed app shows white for a
  while). `/admin/`, `/api/`, `/media/` and `/ajax.php` are never cached.
- A new build takes over immediately (`skipWaiting`, `clientsClaim`); an
  open page reloads once on `controllerchange`.
- Service workers need a secure context: HTTPS or `localhost`.
- The PWA is bound to its address: changing it later means reinstalling and
  logging in again.

## Data model

Shopping data is separated from product knowledge: the catalog is global,
everything that belongs to one purchase (quantity, note, position, state)
lives in the list. All own tables are global QUIQQER tables with prefix `pk_`
in `database.xml`; step 2 creates the catalog, lists, entries and change log,
the other tables come with their steps (marked below).

- **IDs:** lists, entries, products and templates use UUIDs created in the
  browser (`crypto.randomUUID()`, stored lower case, `string(36)`), so they
  can be created offline. Users are QUIQQER user UUIDs (`string(50)`).
  Households, shares and invites are created online only, but use UUIDs too.
- **Times:** `datetime` in server time, JSON as ISO 8601 (`DATE_ATOM`).
- **Deleting:** entries are never deleted while their list exists (removed
  entries stay as rows); lists keep a tombstone (`deleted_at`).

```
QUIQQER user ──owner_user──────────────► pk_lists ◄──household_id── pk_households (9)
     │                                     │  ▲                         ▲
     ├──pk_household_members (9)───────────┼──┼─────────────────────────┘
     ├──pk_list_shares (11) ───────────────┘  │
     │                                        ├── pk_list_invites (11)
     │                                        ├── pk_list_changes (change log)
     │                                        └── pk_list_entries ──product_id──► pk_products
     │                                                                              │  │
     └──owner_user / household_id──► pk_templates (12) ── pk_template_entries ──────┘  │
                                                                                       ├─ category ─► pk_categories
                                                                                       ├─ icon ─► sprite in frontend
                                                                                       └─ image_id ─► QUIQQER media
```

### Catalog

`pk_categories` (step 2, filled in step 8): fixed categories of the catalog.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | string(32), primary | stable key, e.g. `fruit`, `vegetables`, `bread` |
| `position` | integer | order in pickers |
| `color` | string(20) | colour family of the fallback icons (token name, e.g. `green`) |

Names are app texts (`categories.<id>` in `i18n`), not data. Categories never
sort a shopping list.

`pk_products` (step 2; filled from step 3): one global catalog for all signed-in
users.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | string(36), primary | UUID from the browser |
| `name` | string(255) | the only required field; package size may be part of it (“Milch 1,5 l”) |
| `search_name` | string(255), index | name in lower case without accents, for search (step 3) |
| `category` | string(32), null | → `pk_categories.id`; suggested from the name (step 8) |
| `icon` | string(64), null | fallback icon from the sprite (`#pk-<icon>`); null = general icon |
| `description` | text, null | long, permanent description (“die blaue Packung”) |
| `package_size` | string(50), null | “1,5 l”, “500 g”: by hand or from Open Food Facts |
| `barcode` | string(32), null, index | EAN for Open Food Facts (step 14) |
| `image_id`, `image_original_id` | integer, null | QUIQQER media in project `piklis`, folder “Produktbilder” and its “Originale” (step 4) |
| `hidden`, `hidden_at`, `hidden_by` | boolean, datetime, string | hidden by a product maintainer |
| `created_by`, `created_at`, `updated_by`, `updated_at` | | creator may edit own products |

- **Icons** are not a table: the sprite in the frontend holds all icons, a
  product stores the key. Category and icon are independent.
- **Images** are QUIQQER media, not a table: the product points to the square
  image and the reduced original. Sizes 128/640/1600 px come as `.webp`
  addresses of the media cache (step 4).
- **Open Food Facts** (step 14) adds columns to `pk_products`: `brand`,
  `off_data` (JSON: ingredients, nutrition per 100 g/ml and per portion, with
  their reference), `off_language`, `off_fetched_at`. Own name, photo and
  description are never overwritten.
- **Rights** (step 3): everybody may create products and edit their own
  (`created_by`); `piklis.products.manage` may edit all and hide products.
  A list share never grants product rights.
- **Hiding instead of deleting:** hidden products disappear from catalog
  search and suggestions; entries keep pointing to them and show the current
  name and image. Merging products comes later.
- **Catalog sync:** `GET /products` (step 3) returns the whole catalog
  including hidden products (flagged `hidden`), with ETag/304; it is small
  enough for a full download. No revision.

### Lists and entries

`pk_lists`: one list per purchase (not per day), personal or household.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | string(36), primary | UUID from the browser; never changes, also not when moved into the household |
| `name` | string(100) | free name (“Lidl”) |
| `shop_date` | date, null | optional date |
| `owner_user` | string(50), null, index | personal list: its owner; null for household lists |
| `household_id` | string(36), null, index | household list (step 9); exactly one of `owner_user` and `household_id` is set |
| `status` | string(10), index | `active` · `completed` (archive) |
| `completed_at`, `completed_by` | datetime, string, null | last completion |
| `changed_after_completion` | boolean | changes arrived after completion (“nach Abschluss geändert”) |
| `revision` | bigint | +1 per effective operation, see [Revisions](#revisions) |
| `created_by`, `created_at`, `updated_by`, `updated_at` | | last change of anything in the list |
| `deleted_at`, `deleted_by` | datetime, string, null | tombstone; late operations get `list_deleted` |

`pk_list_entries`: a product on a list.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | string(36), primary | UUID from the browser |
| `list_id` | string(36) | → `pk_lists.id`; index `(list_id, revision)` |
| `product_id` | string(36), index | → `pk_products.id` |
| `amount`, `unit` | decimal(10,3), string(16) null | quantity, see [Quantities](#quantities) |
| `note` | string(500) | shopping note for this purchase (“diesmal für den Kuchen”) |
| `sort_key` | string(255) | position, see [Sort keys](#sort-keys) |
| `status` | string(10) | `open` · `done` · `notGot` |
| `checked_amount`, `checked_unit` | decimal, string, null | quantity seen when checking (rule “old check”) |
| `status_changed_at`, `status_changed_by` | | who checked, when |
| `transferred_to` | string(36), null | “not got” taken over into this list (step 7) |
| `removed_at`, `removed_by` | datetime, string, null | removed; the row stays for “wiederherstellen?” |
| `merged_into` | string(36), null | added twice at the same time: alias of the existing entry |
| `changed_after_completion` | boolean | changed after the list was completed |
| `revision` | bigint | list revision of its last change |
| `created_by`, `created_at`, `updated_by`, `updated_at` | | |

- An entry is **visible** when `removed_at` and `merged_into` are null.
- A product appears at most once as an open or not-got entry per list; a
  done entry does not block adding the same product again (“noch mal
  Milch”).
- The same product on two lists are two independent entries (checking milk at
  Lidl does not touch REWE).

`pk_list_changes`: change log, one row per processed operation.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint, auto increment | |
| `operation_id` | string(36), unique | ID of the operation: idempotency |
| `list_id` | string(36) | index `(list_id, revision)` |
| `revision` | bigint, null | revision it created; null if nothing changed (unchanged, conflict) |
| `type`, `entry_id`, `user_id`, `created_at` | | what, which entry, who, when (server time) |
| `payload`, `result` | text (JSON) | the operation as sent and the answer given |

Rejected operations (no access, unknown list, invalid form) are not logged.
Rows may be purged after 90 days (cron, later); an offline queue older than
that would be executed again, which the conflict rules still catch.

### Households, shares, templates (later steps)

| Table (step) | Columns | Notes |
| --- | --- | --- |
| `pk_households` (9) | `id` string(36), `name`, `invite_code` (unique, null), `invite_expires_at`, `created_by`, `created_at` | invite code valid 7 days, reusable, renewable by admins |
| `pk_household_members` (9) | `user_id` (primary: at most one household per person), `household_id` (index), `role` `admin`/`member`, `joined_at` | the last admin must hand over or dissolve |
| `pk_list_shares` (11) | `list_id`, `user_id` (primary together), `invited_by`, `accepted_at` | accepted invitation: role `guest` on this one list |
| `pk_list_invites` (11) | `token_hash` string(64) primary, `list_id`, `created_by`, `created_at`, `expires_at`, `used_at`, `used_by` | one-time link, valid 7 days; only the hash is stored |
| `pk_templates` (12) | `id` string(36), `name`, `owner_user` / `household_id`, `revision`, `created_*`, `updated_*`, `deleted_at` | personal or household, like lists; no external shares |
| `pk_template_entries` (12) | `id`, `template_id` (index), `product_id`, `amount`, `unit`, `sort_key` | products, quantities and order; notes are not stored by default |

Templates are edited explicitly and online (“Vorlage bearbeiten”), saved as a
whole with the template revision (`If-Match`); a concurrent save answers
`409 template_changed`. A list from a template or an earlier purchase is an
independent copy: new IDs, all entries open, sort keys copied.

### Ownership and roles

| Role | Who | May |
| --- | --- | --- |
| `owner` | `owner_user` of a personal list | everything |
| `member` | member of the household in `household_id` | everything; share single lists |
| `guest` | accepted share of this list (`pk_list_shares`) | add, change, sort, check, remove entries; complete and reopen the purchase |

The strongest role counts. Without a role the list does not exist for the
user (`no_access`). Guests may not rename the list, change its date, delete it,
share it further or change where it belongs. Products, other lists, templates
and histories stay protected by their own rules; only the catalog is visible
to everybody.

| Operation | owner | member | guest |
| --- | --- | --- | --- |
| `list.create` (personal) | creator becomes owner | – | – |
| `list.create` (household) | – | members only | – |
| `list.update` (name, date), `list.delete` | ✓ | ✓ | – |
| `list.complete`, `list.reopen` | ✓ | ✓ | ✓ |
| `entry.*` | ✓ | ✓ | ✓ |
| share, revoke, move or copy into the household (REST, steps 9 and 11) | ✓ | ✓ | – |

Implemented in `Sync\ListPermissions`; `Lists\ListRepository::roleOf()` knows
owners now, members (step 9) and guests (step 11) follow with their tables.

## Sync

One way for all changes, online and offline: the app sends **operations**,
each with its own ID; the server applies them in the order they arrive,
counts a **revision** per list and answers each operation. Reading is “all
since revision N”. The server is the authority; the app shows its own pending
operations on top of the last server state.

### Operations

Every operation: `{ "id": "<uuid>", "type": "…", "listId": "<uuid>",
"createdAt": "<ISO time>", … }`, entry operations also `"entryId"`. The ID
makes it idempotent: sent twice, executed once. `createdAt` is informational;
the order is the arrival at the server.

| Type | Fields | Effect |
| --- | --- | --- |
| `list.create` | `name`, `shopDate` (null), `householdId` (null = personal) | new list, revision 1 |
| `list.update` | `fields`: `name` and/or `shopDate` | rename, set or clear the date |
| `list.complete` | – | archive; offer to take over open and not-got entries happens in the app before (step 7) |
| `list.reopen` | – | back to active |
| `list.delete` | – | tombstone |
| `entry.add` | `entryId`, `productId`, `quantity` `{amount, unit}`, `note`, `sortKey` | new entry, status open |
| `entry.set` | `set`: `quantity` and/or `note`; `base`: the same fields with the values the app had before | change quantity or note |
| `entry.move` | `sortKey` | new position |
| `entry.setStatus` | `status` `open`/`done`/`notGot`; with `done` also `seen` `{amount, unit}` | check, uncheck, not got |
| `entry.remove` | – | remove (row stays) |
| `entry.restore` | – | undo a removal, same position |
| `entry.transfer` | `transferredTo` (list id or null) | mark a not-got entry as taken over (step 7) |

Planned: `product.create` (step 3, no `listId`, idempotent through the
product ID) so products can be created offline; the queue sends it before the
`entry.add` that uses it. From step 3 on, `entry.add` with an unknown product
is rejected (`product_not_found`).

Checks (`Sync\Operation`): UUIDs, list name 1–100 characters, date
`YYYY-MM-DD`, note at most 500 characters, valid sort key, valid quantity.
An invalid operation is `rejected` with `invalid_operation`.

### Sending and answers

`POST /ops` with `{ "ops": [ … ] }`, at most 200, processed in order, each
in its own transaction (`Sync\OperationProcessor`). Answer `200` with
`{ "results": [ … ] }`, one result per operation in the same order:

| `status` | Meaning | Other fields |
| --- | --- | --- |
| `applied` | executed | `revision` (new list revision), `entry` or `list` after the change |
| `unchanged` | nothing to do (value already so, already removed, …) | `revision: null`, `entry`/`list` |
| `conflict` | at least one part not executed; other parts may be (then with `revision`) | `conflicts: [ … ]`, `entry` |
| `rejected` | not executed, not logged | `error`, `message` |

Additional flags: `duplicate: true` (operation ID known, the stored answer
is returned, nothing executed again), `mergedInto` (the operation went to
another entry, see [Conflict rules](#conflict-rules)), `reopened` (a done
entry became open again), `afterCompletion` (applied to a completed list).

| `error` | When | App |
| --- | --- | --- |
| `invalid_operation` | wrong form, or an entry ID used in another list | drop, revert locally |
| `list_not_found` | list unknown (never synced) | drop |
| `list_deleted` | list has a tombstone | like `no_access` |
| `no_access` | no role (share revoked, left the household) | keep pending changes visible, offer “als eigene Liste speichern” |
| `forbidden` | role may not do this (guest renames) | drop, revert locally |
| `entry_not_found` | entry unknown in this list | drop |
| `list_exists` | `list.create` with an ID of someone else's list | drop |

HTTP errors only for the whole request: `400 invalid_request` (no `ops`
array), `413 too_many_operations`, `401`, and `500 save_failed` (processing
stopped; already processed operations are committed, the app sends the rest
again and gets `duplicate` for the others).

### Reading

- `GET /lists`: lists of the user without entries, each with `role` and
  `counts` (`open`, `done`, `notGot`, `total` of visible entries). ETag/304.
  Now: own personal lists; household lists (9) and shares (11) follow.
- `GET /lists/{id}`: `{ list (with role), revision, full: true, entries }`
  with all visible entries.
- `GET /lists/{id}?since=N`: `full: false` and only entries with a revision
  above N, including removed entries and aliases (`removed: true`,
  `mergedInto`) so the app can drop them. An unknown N (above the current
  revision) answers like without `since`.
- Errors: `404 list_not_found`, `410 list_deleted`, `403 no_access`.
- Live updates (step 10): poll every 5 s while a list is open and every
  30 s in the overview, only while the app is visible, with ETag (`304`
  costs almost nothing). No WebSockets. The overview compares each list's
  `revision` with the local one and pulls `since` for changed lists.

### Revisions

- Each list has its own counter `pk_lists.revision`, starting with 1 at
  `list.create`. Every operation that changes something adds exactly 1, also
  when it changes several rows (reopen clears marks on many entries).
  `unchanged` and pure conflicts add nothing.
- Every changed entry gets the new revision in `pk_list_entries.revision`;
  “since N” is `revision > N`.
- Writing locks the list row (`SELECT … FOR UPDATE`) for the transaction, so
  revisions of a list have no gaps and follow the order of arrival.

### Field rules

Merging is per field. Independent changes (different fields or entries) are
merged automatically, e.g. checking bread offline while eggs are added online.

| Field | Rule |
| --- | --- |
| quantity (amount and unit together) | compared with `base`: applied if the server still has `base`; if the server already has the new value: `unchanged`; else conflict `quantity` |
| note | the same with conflict `note` |
| sort key | last change (arrival) wins, never a conflict |
| status | last change wins, except the old-check rule |
| removal | wins over later changes of other fields (they ask “wiederherstellen?”) |
| product | fixed after `entry.add`; another product is remove + add |
| list name, date | last change wins |
| list status | last change wins (`list.complete`, `list.reopen`) |

`base` is the value the app showed before the change, including its own
pending changes; the server applies the queue in order, so a series of own
changes does not conflict with itself.

### Conflict rules

| Case | Server | App asks | Answer |
| --- | --- | --- | --- |
| Two people change quantity (or unit) differently at the same time | keeps the first, conflict `quantity` with `yours` and `theirs` | “deine / andere” | “deine”: `entry.set` again with `base` = theirs; “andere”: nothing |
| Two people change the note differently | conflict `note` | “deine / andere” | as above |
| Same new value from both | `unchanged` | – | – |
| Old offline check: checked at 2, meanwhile 3 needed (or another unit) | not checked, conflict `checkedLess` with `checked` and `current` | “2 abgehakt, inzwischen 3 benötigt” (erledigt · offen lassen) | “erledigt”: `entry.setStatus` done with `seen` = current; “offen lassen”: nothing |
| Checked at 2, meanwhile only 1 needed | checked, `checkedQuantity` = 2 | – | – |
| Quantity of a done entry raised above the checked quantity (or unit changed) | applied, entry open again, `reopened: true` | toast “2 abgehakt, 3 benötigt” | – |
| Change (set, move, status, transfer) on an entry removed meanwhile | not applied, conflict `removed` with `removedBy`, `removedAt` | “wiederherstellen?” | yes: `entry.restore`, then the change again with a new ID; no: nothing |
| Restore when the product was added again meanwhile | the old entry becomes an alias of the new one (`mergedInto`) | – | later operations on the old ID go to the new entry |
| Same product added twice at the same time (offline) | no second entry: the new ID becomes an alias (`mergedInto`) of the existing open entry; equal quantity: `applied`; different quantity: conflict `quantity`; an empty note is filled, a different note is a conflict `note` | as above | the app replaces its local entry by the existing one |
| Any change to a list completed meanwhile | applied, entry and list marked `changedAfterCompletion`, list stays in the archive, `afterCompletion: true` | – (shown in the archive as “nach Abschluss geändert”) | – |
| Reopening a completed list | clears all “nach Abschluss geändert” marks | – | – |
| Share revoked, household left, list deleted | `rejected` `no_access` / `list_deleted` for every pending operation | pending changes stay visible with “als eigene Liste speichern” | yes: `list.create` (new ID, personal) and `entry.add` for the local entries; then the local copy is dropped |
| Two people sort at the same time | both applied, the later move wins for the same entry; equal keys sort by entry ID | – | – |

Implemented in `Sync\ListOperationApplier`; each rule has a test in
`tests/unit/Sync/ConflictRulesTest.php` (two people in one household),
idempotency and rights in `OperationProcessorTest.php`.

### Completion, moving, revoking

- **Completion** (`list.complete`) is explicit, also when everything is
  checked, because someone may still add products. Before completing, the app
  offers to take over open and not-got entries (step 7: `entry.add` or
  `entry.set` in the target list plus `entry.transfer` in the origin, which
  stays not got there). Completed lists are read-only in the app until
  reopened; operations that were on their way are still applied and marked.
- **Moving into the household** (step 9, REST, owner only, member of a
  household): sets `household_id`, clears `owner_user`, adds a revision.
  The ID stays, so pending operations keep working; the owner continues as
  member. **Copying** creates a new list (new IDs) and leaves the original.
- **Revoking a share** (step 11) deletes the share row: the list disappears
  from the guest's overview at the next poll; their pending operations get
  `no_access` (see table). The same happens when somebody leaves a household
  (household lists stay there) or a household is dissolved (its lists are
  deleted: `list_deleted`).

### Client side

`frontend/api/operations.js`:

- `operations.*` builds operations from the local entry, so `base` and
  `seen` are always the values on screen.
- `createOperationQueue({ transport, storage })`: `enqueue(op)` adds `id`
  and `createdAt` and sends at once (`autoFlush`); `flush()` sends in order,
  in batches of 200; network errors, `5xx` and `429` keep the operations and
  retry after 1, 3, 10, 30 s; other errors (`401`) wait for the next `flush()`
  (after login, `online`, app visible again: the app decides).
- Unsent changes of the same entry are coalesced: several plus taps become
  one `entry.set` with the first `base`; back at the start value it is
  dropped. Moves of one entry keep only the last key.
- `pending(listId)` and `isEntryPending(entryId)` drive the mark “noch nicht
  übertragen”; `onResult(listener)` gets every answer (conflicts, rejections);
  `pull(listId, since)` reads changes; `applyPulledEntries()` merges them
  into a local map.
- Display state = last server state + pending operations applied on top
  (re-applied after every pull), so own changes never flicker back.
- Storage: in memory now (`createMemoryStorage()`); step 13 adds IndexedDB
  with the same interface `{ load(), save(ops) }`, plus the offline copies of
  lists, catalog and images.

### Sort keys

`Lists\SortKey` (PHP) and `utils/sortKey.js` (JS) with the same test cases
(`tests/fixtures/sort-keys.json`):

- A key is a string of base-36 digits `0-9a-z` read as a fraction between 0
  and 1 (`i` ≈ 0.5); it never ends with `0`, so there is always room below.
- `keyBetween(before, after)` (PHP `between`): `null, null` → `i`; appending
  (`after` null) increments the first digit that is not `z` (`i` → `j` …
  `z` → `zi`), so 500 appended entries need keys of 28 characters; between two keys the midpoint (`i`/`j` → `ii`).
  `keysBetween(before, after, n)` spreads n keys (copies from templates).
- Moving changes only the moved entry. Checked entries keep their key and
  come back to the same place.
- Compared as plain strings (`strcmp`, JS `<`), not by the database
  collation; equal keys (two people inserted at the same gap) sort by entry
  ID. If the neighbours of a drop position have the same key,
  `keyForIndex()` returns null: the app first moves the right neighbour
  (`keyBetween(left, next different key)`) and then the entry.

### Quantities

`Lists\Quantity` (PHP) and `utils/quantity.js` (JS) with the same test cases
(`tests/fixtures/quantities.json`):

- Units: `piece`, `pack`, `bottle`, `cup`, `can`, `jar`, `bag`, `net`,
  `bunch`, `roll`, `bar`, `g`, `kg`, `ml`, `l`; null = without unit (“2 ×”).
- Decimals only for `kg` and `l`, at most 3 places; all other units whole
  numbers from 1. Maximum 9999. In the list 1 is the minimum and removing is
  its own action; in “Produkte hinzufügen” minus at 1 removes the entry.
- Input: comma is the decimal separator; a dot separates thousands when
  exactly three digits follow (`1.500` = 1500, `1.234,5`), otherwise a single
  dot is a decimal point (`1.5`). Error codes: `empty`, `invalid_number`,
  `unknown_unit`, `not_positive`, `not_integer`, `too_many_decimals`,
  `too_large`.
- Display of the number: German, `1,5`, `1.000`, no trailing zeros
  (`formatAmount`); the text with unit and plural (“2 Packungen”) only in the
  frontend (`formatQuantity`). A displayed number parses back to the same
  value.
- Stored as `decimal(10,3)`; PHP compares in thousandths, so `equals()` is
  exact. Package size (“1,5 l”) belongs to the product; the quantity counts
  packages.

## REST API

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/me` | own user: `id`, `name`, `initials` (first and last name), `avatarUrl`, `settings.theme`, `permissions.manageProducts` |
| `PATCH` | `/me/settings` | `theme` (`auto`/`light`/`dark`); answers like `GET /me` |
| `POST` | `/ops` | operations on lists and entries, see [Sync](#sync) |
| `GET` | `/lists` | accessible lists without entries, with `role` and `counts` (ETag) |
| `GET` | `/lists/{id}?since=N` | one list: all visible entries, or with `since` only entries changed after revision N (ETag) |

- Every route in the group requires a QUIQQER session
  (`Rest\SessionAuthentication`), without: `401 unauthorized`. Public routes
  are registered outside the group in `Rest\Provider`.
- Errors: `{ "error": "<code>", "message": "…" }` (`Rest\Json::error`).
- JSON in camelCase. Lists with `Rest\Json::respondWithEtag()`
  (`Cache-Control: private, no-cache`, `304` when unchanged).
- Actions answer with the changed object so the frontend can apply it.

## Login

- Login and logout through the core Ajax functions `ajax_users_login` and
  `ajax_user_logout` (IP lock, failed-attempt counter and origin check stay
  in the core). The returned login template is ignored. Two-factor login is
  not supported by the own form.
- `quiqqer/rest` 3.5 needs an implementation of
  `quiqqer/rest-authentication-implementation`; the instance uses
  `quiqqer/rest-dummy-authentication`, and every route of this package
  checks the session itself. Core REST routes stay closed.
- Session lifetime: `[session] max_life_time` in `etc/conf.ini.php`.
- Native apps later: `quiqqer/oauth-server` (authorization code + PKCE),
  only `api/index.js` gets the bearer token.

## Background work

- Long-running work belongs in a console command; the cron calls the same
  code (`Console\Hello` as example).
- A button in the app starts the command as its own process
  (`php console <command> --run=<id>`) and answers `202` at once; status in
  an own table (`queued` › `running` › `done`/`failed`), the app polls.
  Lock against parallel runs with `flock` on a file under
  `var/package/<vendor>/<package>/`. Filmoteka’s NAS scan is the reference.
