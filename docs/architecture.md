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
| `events.xml` | `onRequest`: service worker and manifest; `onPackageSetup`: base catalog once (`Setup\EventHandler`) |
| `data/` | `icons.json` (fallback icons, category, source), `keywords.json` (suggestion), `catalog.json` (base catalog), read by PHP and JS |
| `build/make-sprite.mjs`, `build/icons/` | generates the icon sprite (`npm run icons`) from Lucide, the design package and own icons |
| `LICENSE-lucide.txt` | ISC licence of the Lucide icons in the sprite |
| `console.xml`, `cron.xml` | example command `pk:hello`, the same code as cron (created inactive); `pk:clean-list-items` with its daily cron at 03:30 (`required`, so the package setup creates it; see [List items](#list-items)) |
| `locale.xml`, `locale/*.xml` | backend texts (see [Texts](#texts)) |
| `standalone.html` | content of the start page |

PHP under `src/MichaelDanielczok/Piklis/`, grouped by area: `Rest/`
(endpoints), `Sync/` (operations, conflict rules, without database),
`Lists/` (sort keys, quantities, `ListRepository`, roles `ListAccess`,
moving and copying `ListOwnership`, sharing single lists `ListSharing` with
`ShareRepository`), `Households/` (household rules
`HouseholdService`, invite code, `HouseholdRepository`), `Templates/`
(templates: rules `TemplateService`, input `TemplateInput`,
`TemplateRepository`), `Products/` (catalog:
input, rights, search, suggestion, images, `ProductRepository`), `Setup/`
(base catalog on package setup), `Images/` (limits,
upload checks, media storage, cache addresses), `Database/` (table names),
`Support/` (server UUIDs), `Users/`, `Pwa/`, `Console/`.

## Frontend

Sources under `frontend/`, built by Vite into `bin/app/`. The build is
committed because the server does not build (no Node there).

| Path | Content |
| --- | --- |
| `app.js`, `App.vue`, `router.js` | entry, shell, routes |
| `api/index.js` | the only module that talks to the server (REST and core Ajax) |
| `api/operations.js` | operations: builders, queue, sending, pull since revision (see [Sync](#sync)) |
| `api/localStore.js` | local storage in IndexedDB without a library: the queue and the offline copies (see [Offline](#offline)) |
| `composables/` | shared state: session (with the household summary), household (`useHousehold.js`), templates with their drafts (`useTemplates.js`), toast, page transition, product catalog (`useProducts.js`), server settings (`useConfig.js`), media query, shopping lists (`useLists.js`), sorting by the drag handle (`useDragSort.js`), swiping a row (`useRowSwipe.js`), holding and sliding for the quantity control (`useQuantityHold.js`) |
| `components/layout/` | page frame and header, sidebar, mobile navigation, nav items, user menu |
| `components/ui/` | button, sheet, section label, theme switch, avatar, logo, empty state, search field |
| `components/list/` | list row, row group, skeleton row, quantity, add tile, input bar with its suggestions, scan sheet and variant switch (`AddBar*.vue`, `addBarMode.js`) |
| `components/lists/` | overview card and its skeleton, sheet “Neue Liste / Liste bearbeiten” (with start from an earlier purchase), sheet for quantity and note of an entry (for list items also photo and description), card “In der Liste …” (`EntryContextCard.vue`), transfer and completion sheet (`TransferSheet.vue`), product checklist (`EntryChecklist.vue`), sheet “Liste teilen” / “Geteilte Liste” (`ListShareSheet.vue`) |
| `components/templates/` | sheet “Neue Vorlage / Vorlage verwalten” (`TemplateFormSheet.vue`), sheet “Als Vorlage speichern” with the product check (`TemplateSaveSheet.vue`) |
| `components/household/` | member options (`MemberSheet.vue`), leaving, switching and dissolving with the offer of personal copies (`HouseholdLeaveSheet.vue`) |
| `components/products/` | product image (photo or fallback icon), fallback icon, catalog row and its skeleton, detail, form, icon picker, photo field, crop dialog, large view |
| `products/` | fixed categories and their colour families (`categories.js`), fallback icons with their category (`catalog.js`), suggestion from the name (`suggest.js`) |
| `icons/` | fallback icon sprite (`ersatzicons.svg`, generated by `npm run icons`, embedded once at start) |
| `utils/quantity.js` | quantities with unit (`2 ×`, `2 Packungen`, `1,5 kg`), input with comma, checks |
| `utils/sortKey.js` | sort keys between two neighbours (same logic as `Lists\SortKey`) |
| `utils/productSearch.js` | catalog search: normalising and ranking (same logic as `Products\ProductSearch`) |
| `utils/listItems.js` | list items in the app: merging, finding the ones taken into the catalog, storing them with their list (tested with `node --test`) |
| `utils/listState.js` | local state of a list: server state plus own operations, counts, append key, move plan (tested with `node --test`) |
| `utils/takeOver.js` | taking over into another list (same merge as `Sync\TakeOverMerge`), plan with unit questions, entries for a new list from an earlier purchase |
| `utils/templates.js` | products of a list for a template (`templatePlan`), the draft while editing (quantity, move, changed?) (tested with `node --test`) |
| `utils/shopDate.js` | optional shopping date relative to today (“heute”, “Mo”, “Sa., 24. Okt.”) |
| `utils/imageCrop.js`, `utils/imageUpload.js` | crop and zoom maths, file checks against the limits, pasted images (tested with `node --test`) |
| `utils/imageEncode.js` | load an image, draw crop and reduced original into a canvas, encode as JPEG |
| `views/` | one view per route |
| `styles/tokens.css` | all colours, sizes and spacing as CSS variables |
| `styles/forms.css` | form fields (`form-field`, label, input, hint, error) |
| `i18n/` | `t()`, texts in `locales/de.js`, `Intl` for numbers, dates and plurals |
| `public/icons/` | PWA icons and favicons (from `build/*.svg`) |
| `views/samples/` | sample photos for the pattern page, dev server only |

- Routes (hash): `/login` (`meta.public`); navigation `/` (Listen),
  `/products` (Produkte), `/templates` (Vorlagen); archive `/archive`;
  shopping list
  `/lists/:id`, its product picker `/lists/:id/add` and an entry
  in the list context `/lists/:id/entries/:entryId` (all highlight
  “Listen”); product pages
  `/products/new?name=…`, `/products/:id`, `/products/:id/edit`; a template
  `/templates/:id` and its product picker `/templates/:id/add` (highlight
  “Vorlagen”); from the user menu
  `/profile`, `/household`, `/settings`; the invitation to a list
  `/invite/:token` (link from “Liste teilen”); `/components` (pattern page) only in
  the dev server (`import.meta.env.DEV`, missing in the build). Everything
  except public routes requires a session; `meta.manageProducts`
  additionally requires `piklis.products.manage`. A `401` from the API
  returns to login with `?redirect=`.
- Login lifetime: 90 days (`Users\SessionLifetime`). The package setup raises
  `session.max_life_time` in `etc/conf.ini.php`. The core renews the cookie
  only after a long pause, which live polling never reaches, so `GET /me`
  renews it at app start, at most once a day (`Session::refresh()`; the old
  ID stays valid for requests in flight).
- On-screen keyboard: iOS does not shrink the page and Safari ignores
  `interactive-widget`. `utils/keyboardInset.js` turns Ionic's
  `ionKeyboardDidShow` / `ionKeyboardDidHide` (from `visualViewport` in the
  PWA, from the Keyboard plugin in a native app) into `--keyboard-height` and
  `data-keyboard="open"` on `<html>`. `AppPage` keeps that space free at the
  bottom, `MobileNav` is hidden.
- Input bar: `ListView` shows `AddBar` in active lists, see
  [Input bar](#input-bar). `App.vue` hides `MobileNav` in an active list and
  in its sub-views (`list-add`, `list-entry`, and `product` or `product-edit`
  when reached from there), so it does not flash in and out when going there
  and back; the way back is the arrow.
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
  `AppPage` `width`: `wide` · `list` · `narrow`; `stickyHead` keeps the
  header and the `head` slot at the top; the `dock` slot holds a main action
  that sticks above the navigation (`slot="fixed"` of `IonContent`, because
  Ionic's page transition only moves the content).
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
  above the floating bar and, on pages with a `dock`, above that button.
- **Sheets** (`AppSheet.vue`): Ionic modal as sheet with automatic height,
  radius 22 px at the top, handle, title and subtitle, round close button,
  scrim from the tokens; centred from `45em`. Head and buttons stay, only the
  content scrolls, without a visible scrollbar; the class
  `ion-content-scroll-host` tells Ionic's swipe gesture to start there only at
  scroll position 0. iOS lifts the sheet above the keyboard itself by moving
  the whole page, so it needs no `--keyboard-height`.
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
| `products/ProductImage.vue` | photo or fallback icon with the same size and radius (`row` 44 px, `tile` 40 px, `lg` 80 px, `hero` square up to 240 px in the detail); `muted` for done rows and hidden products; a broken photo falls back to the icon |
| `products/ProductIcon.vue` | one fallback icon from the sprite (`#pk-<name>`, unknown names show `allgemein`) |
| `list/ListRow.vue` | list row with three separate touch areas: handle (32 px wide, full row height), details (image, name max. 2 lines, note or status, quantity), check zone (60 px wide, full row height, ring 28 px). States `open` · `done` · `notGot` (transfer button instead of the ring), plus `unsynced` |
| `list/RowGroup.vue` | card for rows, separators instead of single cards |
| `list/SkeletonRow.vue` | loading row with exactly the end height (64 px) and the same zones, flat without shimmer |
| `list/QuantityLabel.vue` | quantity, semibold with tabular figures |
| `list/AddTile.vue` | tile in “Produkte hinzufügen”: as tall as the tallest tile of its grid row (from 80 px), image 40 px with `meta` beside it, name in 14 px, a tap anywhere adds 1, badge with the quantity, inset outline when selected; `meta` (package size, “erledigt”); holding opens the quantity control (`open` for “Details”) |
| `list/QuantityControl.vue` | quantity control for tile and row: scale next to the finger while holding, small dialog with −, +, “Details”, “Entfernen” otherwise; see [Quantity control](#quantity-control) |
| `list/AddTileSkeleton.vue` | loading tile in the same height |
| `ui/SectionLabel.vue` | section heading in capitals (“ERLEDIGT · 8”), capitals by CSS only |
| `ui/AppSheet.vue` | bottom sheet (see above) |
| `ui/AppButton.vue`, `ui/ThemeSwitch.vue`, `ui/UserAvatar.vue`, `ui/AppLogo.vue`, `ui/EmptyState.vue` | button (`primary`, `secondary`, `danger`, `danger-solid`), theme choice, avatar or initials, app icon with name, placeholder of a view |

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

Interface icons (Lucide) use the same line width 1.75 as the fallback
icons (`setLucideProps` in `App.vue`); only status symbols (check mark,
plus in tiles, toast) are drawn thicker on purpose.

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
  while). Product images in list and detail size
  (`/media/cache/…__128x128.webp`, `__640x640.webp`) use `CacheFirst` in
  the cache `pk-images` (600 entries, 60 days; every new image has a new
  address). `/admin/`, `/api/`, `/ajax.php`, the large view and originals
  are never cached; data offline lives in IndexedDB (see [Offline](#offline)).
- The app asks for persistent storage (`navigator.storage.persist()`), so
  the browser does not clear the offline copies under storage pressure.
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
  entries stay as rows); lists keep a tombstone (`deleted_at`). Catalog
  products are never deleted; unused list items are, so `product_id` of a
  removed entry or an entry on a deleted list may point to nothing.

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
                                                                                       ├─ category ─► Products\Categories (fixed keys)
                                                                                       ├─ icon ─► sprite in frontend
                                                                                       └─ image_id ─► QUIQQER media
```

### Catalog

Categories are not a table: fixed keys in code (`Products\Categories`,
`frontend/products/categories.js`), names are app texts
(`categories.<key>`), colours are CSS tokens (see
[Fallback icons and category colours](#fallback-icons-and-category-colours)).
Step 2 created a table `pk_categories`; step 8 removed it from
`database.xml` because nothing read it and a third copy of the keys could
drift (QUIQQER's setup does not drop tables: an empty `pk_categories` may
remain in older instances and can be dropped by hand). Categories never sort
a shopping list.

`pk_products` (step 2; filled from step 3): one global catalog for all signed-in
users.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | string(36), primary | UUID from the browser |
| `name` | string(255) | the only required field; package size may be part of it (“Milch 1,5 l”) |
| `search_name` | string(255), index | name normalised for search (`Products\SearchText`), rebuilt on every write |
| `category` | string(32), null | key from `Products\Categories`; suggested from the name (step 8) |
| `icon` | string(64), null | fallback icon from the sprite (`#pk-<icon>`); null = general icon |
| `description` | text, null | long, permanent description (“die blaue Packung”) |
| `package_size` | string(50), null | “1,5 l”, “500 g”: by hand or from Open Food Facts |
| `brand` | string(120), null | brand, by hand or from Open Food Facts (step 14) |
| `barcode` | string(32), null, index | GTIN linking the product to Open Food Facts (step 14) |
| `off_data` | text, null | JSON `{ ingredients, nutrition }` taken over from Open Food Facts |
| `off_language`, `off_fetched_at` | string(5), datetime, null | language of the ingredients, fetch date; no date = no Open Food Facts data |
| `image_id`, `image_original_id` | integer, null | QUIQQER media in project `piklis`, folder “Produktbilder” and its “Originale” (step 4) |
| `image_source` | string(20), null | null = own photo, `openfoodfacts` = taken over (licence note) |
| `hidden`, `hidden_at`, `hidden_by` | boolean, datetime, string | hidden by a product maintainer |
| `list_item` | boolean, index | not in the catalog, see [List items](#list-items) |
| `created_by`, `created_at`, `updated_by`, `updated_at` | | creator may edit own products; `catalog` = base catalog without creator |

- **Icons** are not a table: the sprite in the frontend holds all icons, a
  product stores the key. Category and icon are independent.
- **Images** are QUIQQER media, not a table: the product points to the square
  image and the reduced original. Sizes 128/640/1600 px come as `.webp`
  addresses of the media cache, see [Product images](#product-images).
- **Open Food Facts** (step 14): the columns above plus the cache table
  `pk_off_cache`, see [Open Food Facts](#open-food-facts). Own name, photo
  and description are never overwritten unasked.
- **Rights:** see [Product catalog](#product-catalog).
- **Hiding instead of deleting:** hidden products disappear from catalog
  search and suggestions; entries keep pointing to them and show the current
  name and image. Merging products comes later.
- **Catalog sync:** `GET /products` returns the whole catalog
  including hidden products (flagged `hidden`) but without list items, with
  ETag/304; it is small enough for a full download. No revision.

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
| `transferred_to` | string(36), null | “not got” taken over into this list (`entry.transfer`) |
| `removed_at`, `removed_by` | datetime, string, null | removed; the row stays for “wiederherstellen?” |
| `merged_into` | string(36), null | added twice at the same time: alias of the existing entry |
| `changed_after_completion` | boolean | changed after the list was completed |
| `revision` | bigint | list revision of its last change |
| `created_by`, `created_at`, `updated_by`, `updated_at` | | |

- An entry is **visible** when `removed_at` and `merged_into` are null.
- A product appears at most once as an open entry per list; a done or
  not-got entry does not block adding the same product again (“noch mal
  Milch”): both record what happened in this purchase.
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

### Households, shares, templates

| Table (step) | Columns | Notes |
| --- | --- | --- |
| `pk_households` (9, built) | `id` string(36) (server UUID), `name` string(100), `invite_code` string(20) (unique, null), `invite_expires_at`, `created_by`, `created_at` | invite code valid 7 days, reusable, renewable by admins; see [Household](#household) |
| `pk_household_members` (9, built) | `user_id` string(50) (primary: at most one household per person), `household_id` (index), `role` `admin`/`member`, `joined_at` | the last admin must hand over or dissolve |
| `pk_list_shares` (11, built) | `list_id`, `user_id` (primary together, index `user_id`), `invited_by`, `accepted_at` | accepted invitation: role `guest` on this one list; revoking or leaving deletes the row |
| `pk_list_invites` (11, built) | `token_hash` string(64) primary, `list_id` (index), `created_by`, `created_at`, `expires_at`, `used_at`, `used_by` | one-time link, valid 7 days; only the SHA-256 hash of the token is stored; see [Sharing single lists](#sharing-single-lists) |
| `pk_templates` (12, built) | `id` string(36) (UUID from the browser), `name` string(100), `owner_user` / `household_id` (index), `revision` (starts at 1), `created_*`, `updated_*`, `deleted_at`, `deleted_by` | personal or household, like lists; no external shares; tombstone when deleted |
| `pk_template_entries` (12, built) | `id`, `template_id` (index), `product_id` (index), `amount`, `unit`, `note` string(500), `sort_key` | each product at most once; notes only when the user chose to keep them |

Templates are edited explicitly and online (“Vorlage bearbeiten”), saved as a
whole with the template revision (`If-Match`); a concurrent save answers
`409 template_changed`. A list from a template or an earlier purchase is an
independent copy: new IDs, all entries open. See [Templates](#templates).

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
to everybody. Templates know only `owner` and `member`
(`Templates\TemplateService::roleOf`): a guest of a list never sees a
template.

| Operation | owner | member | guest |
| --- | --- | --- | --- |
| `list.create` (personal) | creator becomes owner | – | – |
| `list.create` (household) | – | members only | – |
| `list.update` (name, date), `list.delete` | ✓ | ✓ | – |
| `list.complete`, `list.reopen` | ✓ | ✓ | ✓ |
| `entry.*` | ✓ | ✓ | ✓ |
| share, revoke, move or copy into the household (REST, steps 9 and 11) | ✓ | ✓ | – |

Implemented in `Sync\ListPermissions`; the role comes from `Lists\ListAccess`
(used by `Lists\ListRepository::roleOf()` and the test store): owner of a
personal list, member of the household the list belongs to, guest with a row
in `pk_list_shares`. A household member never sees the personal lists of other
members; a guest who joins the list's household becomes member.
Every list route checks the role: `GET /lists` (only accessible lists),
`GET /lists/{id}` (`403 no_access`), `POST /ops` (per operation), `POST
/lists/{id}/move` and `/copy` (`Lists\ListOwnership`).

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
| `list.complete` | – | archive; the offer to take over open and not-got entries happens in the app before |
| `list.reopen` | – | back to active |
| `list.delete` | – | tombstone |
| `entry.add` | `entryId`, `productId`, `quantity` `{amount, unit}`, `note`, `sortKey` | new entry, status open |
| `entry.set` | `set`: `quantity` and/or `note`; `base`: the same fields with the values the app had before | change quantity or note |
| `entry.move` | `sortKey` | new position |
| `entry.setStatus` | `status` `open`/`done`/`notGot`; with `done` also `seen` `{amount, unit}` | check, uncheck, not got |
| `entry.remove` | – | remove (row stays) |
| `entry.restore` | – | undo a removal, same position |
| `entry.transfer` | `transferredTo` (list id or null) | mark a not-got entry as taken over into that list |
| `entry.takeOver` | `entryId`, `productId`, `quantity`, `note`, `sortKey`, `onUnitMismatch` (`keep` · `replace` · `note`, default `note`), `quantityText` (≤ 100), `fromListId` (information) | take over an entry of another list, see [Taking over](#taking-over) |

| `product.create` | `productId`, `product` `{name, category, icon, description, packageSize, listItem}`; no `listId` | new product, with `listItem: true` a list item (`Sync\ProductOperation`) |

`product.create` lets the app create products offline; the queue sends it
before the `entry.add` that uses it. It is idempotent through the product ID,
not through the change log: the same ID from the same user answers
`unchanged` with the stored product (nothing overwritten), from another user
`rejected` `product_exists`; it adds no list revision and is not logged in
`pk_list_changes`. `entry.add` with an unknown product is rejected
(`product_not_found`); hidden products may still be added.

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
| `product_not_found` | `entry.add` with a product that is not in the catalog | send the `product.create` first; else drop |
| `product_exists` | `product.create` with the ID of someone else's product | drop, create with a new ID |

HTTP errors only for the whole request: `400 invalid_request` (no `ops`
array), `413 too_many_operations`, `401`, and `500 save_failed` (processing
stopped; already processed operations are committed, the app sends the rest
again and gets `duplicate` for the others).

### Reading

- `GET /lists`: lists of the user without entries, each with `role`,
  `counts` (`open`, `done`, `notGot`, `total` of visible entries) and
  `preview` (product IDs of the first four open entries in list order,
  `Lists\ListPreview`, for the images in the overview), plus the user's
  `household` in short form (`{ id, name, role }` or null, as in `GET /me`),
  so the polling overview notices removal or dissolution. ETag/304.
  Own personal lists, the lists of the user's household (role `member`) and
  lists shared with the user (role `guest`, plus `sharedBy` `{ id, name }`:
  who created the link).
- `GET /lists/{id}`: `{ list (with role; guests also `sharedBy`), revision,
  full: true, entries, products }` with all visible entries; `products` are
  the list items on them (see [List items](#list-items)).
- `GET /lists/{id}?since=N`: `full: false` and only entries with a revision
  above N, including removed entries and aliases (`removed: true`,
  `mergedInto`) so the app can drop them. An unknown N (above the current
  revision) answers like without `since`. `products` still holds all list
  items of the list: changing one adds no revision.
- Errors: `404 list_not_found`, `410 list_deleted`, `403 no_access`.
- Live updates: poll every 5 s while a list is open (`refreshList`, “since
  N”) and every 30 s in the overview (`loadLists`), only while the app is
  visible (`composables/useLivePoll.js` on `utils/poller.js`: started by
  `ionViewDidEnter`, stopped by `ionViewWillLeave`, paused on
  `visibilitychange` hidden, one poll at once visible again or `online`;
  never two polls at the same time, the next one `interval` after the end
  of the last). ETag: the responses carry `Cache-Control: private,
  no-cache`, so the browser asks with `If-None-Match` and an unchanged list
  answers `304` (about 300 bytes). No WebSockets.
- The overview compares each loaded list's `revision` with the local one
  and pulls “since N” for changed lists (so cards of loaded lists, whose
  counts the app computes itself, stay current); a loaded list that is
  missing from the answer (removed from the household, household
  dissolved, list deleted) is dropped (`missing`), unless it was created
  locally after the request started. A changed `household` updates the
  session (default for new lists) and lets `useHousehold` reload.

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
  offers to take over open and not-got entries (`entry.takeOver` in the
  target list, then `entry.setStatus` notGot for open ones and
  `entry.transfer` in the origin, which keeps them as not got). Completed
  lists are read-only in the app until reopened; operations that were on
  their way are still applied and marked.

### Taking over

`entry.takeOver` in the target list (`Sync\ListOperationApplier::takeOver`,
merge rules in `Sync\TakeOverMerge`, cases shared with
`utils/takeOver.js` in `tests/fixtures/take-over.json`):

- The product is not open in the target (missing, done or not got there): a
  new open entry with quantity, note and the sent sort key; answer
  `takeOver: added`.
- The product is open in the target: no second entry, the new ID becomes an
  alias (`mergedInto`) of the open entry, which gets
  - same unit (also both without): the sum, at most 9999 (`summed`);
  - another unit: by `onUnitMismatch`: `keep` (`kept`), `replace`
    (`replaced`) or `note`: quantity stays, the note gets “+ 400 g”
    (`quantityText` from the app, texts with units only live in the
    frontend; without it number and unit key) (`noted`);
  - notes: an empty note is filled, another one appended with “ · ”, one
    already contained is not repeated; at most 500 characters.
- The app asks about another unit before sending (it loads the target
  list first); if the target changed meanwhile and the server meets an
  unexpected unit, the default `note` loses nothing.
- Idempotent through the operation ID, and through the entry ID: a rebuilt
  operation with the same `entryId` answers `unchanged`.
- Guests may take over (entry operation). A completed target list is marked
  “nach Abschluss geändert” like any other change.
- **Moving into the household** (`POST /lists/{id}/move`, owner only, member
  of a household): sets `household_id`, clears `owner_user`, adds a revision
  and a change log row `list.move`. The ID stays, so pending operations keep
  working; the owner continues as member, and the list stays in the
  household when they leave.
- **Copying** (`POST /lists/{id}/copy` `{ id, to }`): a new, independent list
  with the browser's ID (idempotent: the same ID again from the same user is
  `unchanged`) and new entry IDs; the original stays. `to: household` for
  personal lists (owner), `to: personal` for household lists (members, the
  “persönliche Kopie” before leaving). Name, date, completion and the visible
  entries with quantity, note, sort key and state are copied; change log row
  `list.copy`, revision 1.
- **Revoking a share** deletes the share row: the list disappears from the
  guest's overview at the next poll, an open list shows “Kein Zugriff mehr”
  at its next poll (5 s); their pending operations get `no_access` (see
  table; “als eigene Liste speichern”, see [Offline](#offline)). The same happens when somebody leaves a household
  (household lists stay there) or a household is dissolved (its lists are
  deleted: `list_deleted`).

### Client side

`frontend/api/operations.js`:

- `operations.*` builds operations from the local entry, so `base` and
  `seen` are always the values on screen.
- `createOperationQueue({ transport, storage })`: `enqueue(op)` adds `id`
  and `createdAt` and sends at once (`autoFlush`); once the storage is
  loaded the operation is in `pending()` and `onChange` listeners know it
  within the same call (before saving), so several taps in one tick see
  each other and never add a product twice; `flush()` sends in order,
  in batches of 200; network errors, `5xx` and `429` keep the operations and
  retry after 1, 3, 10, 30 s; other errors (`401`) wait for the next `flush()`
  (after login, `online`, app visible again: the app decides).
- Unsent changes of the same entry are coalesced: several plus taps become
  one `entry.set` with the first `base`; back at the start value it is
  dropped. Moves of one entry keep only the last key.
- Polls and answers to own operations arrive in any order: an entry with a
  lower `revision` than the known one never replaces it (`mergeEntries`),
  a pull answer older than the known revision is ignored, and an empty
  answer “since N” (same revision, same list head, `changesState`) changes
  nothing, so Vue does not render every 5 s.
- `pending(listId)` and `isEntryPending(entryId)` drive the mark “noch nicht
  übertragen”; `onResult(listener)` gets every answer (conflicts, rejections);
  `pull(listId, since)` reads changes; `applyPulledEntries()` merges them
  into a local map.
- Display state = last server state + pending operations applied on top
  (re-applied after every pull), so own changes never flicker back.
- `composables/useLists.js` holds the one queue of the app and the loaded
  lists: display state = server state + pending operations
  (`utils/listState.js`, `applyOperations`), so a rejected operation simply
  disappears (toast “Eine Änderung hat der Server abgelehnt.”). Conflicts
  are collected in `conflicts` (one item per conflict, oldest first) and
  asked in `components/lists/ConflictSheet.vue`, mounted in `App.vue` so
  they arrive on every page: quantity and note “Deine Änderung · Andere
  Änderung”, old check “2 abgehakt, inzwischen 3 benötigt” (Erledigt ·
  Offen lassen), removed entry (Wiederherstellen · Entfernt lassen);
  `resolveConflict()` sends the answer as in the table above, closing the
  sheet without a choice keeps the list as it is (the server state is
  already shown). `entry.takeOver` asks before sending (unit step in the
  transfer sheet); a unit met only on the server falls back to `note`,
  which loses nothing, so there is no conflict sheet for it. Answers
  update the server state (`entry`, `list`); a revision that is not exactly
  +1 means someone else changed the list, then it pulls “since N” once
  nothing is pending. Sending again on `online` and when the app becomes
  visible. While sending fails, pending entries show “noch nicht
  übertragen” and the list a blue offline note with the number of waiting
  changes.
- Storage: IndexedDB (`localStore.queueStorage`, see [Offline](#offline));
  `createMemoryStorage()` remains for tests.

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

## Offline

Concept in Obsidian “Piklis Konzept” › “Offline und Synchronisierung”,
decisions “Lokaler Speicher” and “Freigabe entzogen”.

- **Local storage** (`api/localStore.js`, IndexedDB `piklis`, own module
  without a library): store `ops` holds the queue, one record per operation
  (`{ seq, op }`, key = operation ID); `save(ops)` writes only the
  difference to the last call in one transaction, transactions on the same
  store run in call order, so the last reported state always wins, also
  for fast repeated taps (the queue reports an operation before it is
  saved). Store `data` holds the offline copies: `me` (user), `overview`
  (`GET /lists`), `list:<id>` (server state `{ list, revision, entries,
  products }` of every active list, and of a list with pending operations;
  `products` are its list items),
  `orphan:<id>` (see below), `products` and `suggestions`, plus
  `meta:user`. Values go in as JSON (Vue proxies cannot be cloned). Without
  IndexedDB the same module works in memory.
- **One user**: `bindUser(id)` after the session is known; data of another
  user is deleted first, the queue only loads after that. Signing out
  deletes everything (`wipe`); an expired session (401) keeps everything, so
  pending changes are sent after signing in again as the same user.
- **Start**: `loadSession()` asks `GET /me`; without a server (network
  error, 5xx) it uses the stored user and starts offline. Before the first
  view the modules restore their state (`onSessionStart`: overview, lists,
  orphans, catalog, suggestions, locally created products from pending
  `product.create`) and the queue is sent. Without a stored user the login
  page says that the server is unreachable.
- **What is offline**: after every `GET /lists` the app fetches every
  active list it has not loaded yet (`prefetchActiveLists`), also when it
  starts on another view; the overview and these lists are written shortly
  after every change (`persistNow`, 300 ms, only changed keys, and on
  `pagehide`); lists that are no longer active or accessible are removed.
  The catalog is written after every confirmed change. Images of the
  products on these lists (128 and 640 px) are fetched once into
  `pk-images` (`utils/offlineImages.js`, only with an active service
  worker, four at a time). Templates are online only (decision): the view
  keeps what it loaded in this session with a note, otherwise it says
  “Vorlagen nur mit Verbindung”.
- **Offline use**: check, uncheck, not got, quantities and notes, adding
  from the local catalog (also new products), sorting, completing and
  creating lists all go through the queue and are shown at once. Moving,
  copying and sharing are REST calls: offline they answer “Das geht nur mit
  Verbindung.”
- **Display**: connection state in `composables/useConnection.js` (a
  request failed with a network error, browser `offline`, or the dev
  switch). While offline or while sending fails: blue note at the top of
  the overview and of the list (`OfflineNote.vue`) with the number of
  waiting changes, and every entry with a pending change shows a cloud and
  “noch nicht übertragen” on its own line (also checked and not-got
  entries). A list that is not on the device shows “Nicht auf diesem
  Gerät”.
- **Reconnecting**: `online`, app visible again, retry timer (1/3/10/30 s)
  or the dev switch send the queue in order; answers and polls merge as
  usual, conflicts (quantity, note, old check, removed) open the conflict
  sheet. Expected network errors of background loads are logged as info
  (`logError`), not as errors.
- **Share revoked, list deleted** (`no_access`, `list_deleted` for pending
  operations): the rejected operations are kept per list as an orphan
  (last known server state plus the rejected operations, stored as
  `orphan:<id>`). The list shows “Kein Zugriff mehr” or “Liste gelöscht”
  and below it, like the overview, `OrphanNotice.vue`: “Als eigene Liste
  speichern” creates a personal list with the name and the entries
  including the own changes (`list.create`, `entry.add`, status for done
  and not-got entries); “Änderungen verwerfen” drops them. Either way the
  local copy disappears.
- **Testing** in the dev server: Einstellungen › Entwicklung › “Offline
  simulieren” (`setSimulatedOffline` in `api/index.js`, kept in
  `localStorage` `pk.dev.offline` across reloads): every API request fails
  like without network. Images are not cached in the dev server (no service
  worker); test the PWA with the build through the mock backend at
  <http://localhost:8180/> (see docs/development.md). Einstellungen shows
  what is stored (lists, products, images, size, waiting changes).

## Shopping lists

Concept in Obsidian “Piklis Konzept” › “Einkaufslisten”; design package
screens 01–07 and 10.

- **Overview** (`ListsView.vue`, `ListCard.vue`): active lists, newest
  first by creation (the order does not change while shopping). Card: name,
  date (`utils/shopDate.js`), origin chip (“nur ich” with a person, or
  “Haushalt” with a house in green; screen readers get the full meaning), progress
  bar with “8 von 30”, images of the first open products and “+ N” for the
  other open ones. The card has a fixed structure, the skeleton
  (`ListCardSkeleton.vue`) the same heights. The device remembers the last
  loaded number of active lists per user (`localStorage`,
  `pk.activeListCount.<user>`, at most 8) and shows that many skeleton
  cards; with 0 an invisible empty state keeps the height, so the cards do
  not move when the lists arrive (only if the number changed on another
  device). “Neue Liste” sticks above the navigation (`dock` slot of
  `AppPage`, also “Neue Vorlage” and, on phones, “Neues Produkt”; the
  content gets that much more room at the end). It opens `ListFormSheet.vue`:
  only the name and “Anlegen” show, “Erweitert” folds out date (Ohne Datum ·
  Heute · Morgen · date field), “Für wen?” and “Wie möchtest du starten?”,
  which keep their defaults. The name is optional for a new list (at most 100
  characters): empty gives “Einkaufsliste – Do, 05.11.” with the date of the
  list, otherwise today, and the placeholder shows that name. Editing a list
  shows everything and requires a name. The new list opens at once, also
  before the server answered.
- **List** (`ListView.vue`): title with origin and date, plus button
  (add) and “…” (edit: name, date, delete with confirmation in the same
  sheet). Progress “8 von 30 erledigt · 22 offen”, open entries in sort-key
  order, a dashed “Produkte hinzufügen” row, “Nicht bekommen · N” with
  “Alle übernehmen …” (shown when present) and “Erledigt · N”, collapsible
  (remembered per device in `localStorage`, `pk.listDoneOpen`); at the
  bottom “Einkauf abschließen”. Without the user menu in the header (room
  for the actions).
- **Swiping a row** (`useRowSwipe.js`, Pointer Events, `touch-action:
  pan-y`): swiping to the left reveals two buttons of 88 px each, “Nicht
  bekommen” in grey and “Entfernen” in red; in the section “Nicht bekommen”
  “Wieder offen” in green and “Entfernen”. The row stops at the buttons:
  swiping never runs anything, however far, only tapping a button does.
  Swiping to the right stays free for the back gesture. Done rows have no
  swipe. Only one row is open at a time; tapping another row only closes
  it. `ListRow` takes the buttons as `swipe` (`{ action, label, variant }`)
  and reports `swipe-action` with `action`.
- **Not got** (design screens 04, 06, 07): the row closes like checking and
  moves into “Nicht bekommen” (cross and text, sort key unchanged), toast
  with “Rückgängig”. There, the round arrow button opens the transfer sheet.
- **Removing by swipe**: `entry.remove`, the row closes through the
  `TransitionGroup` like a removal by someone else, toast with “Rückgängig”
  (`entry.restore`, same as in the product); the entry comes back at its
  place and in its state, because sort key and status stay.
- The swipe buttons are a shortcut for the finger (`aria-hidden`, not
  focusable): the same actions are buttons in the product in the list
  context (`EntryContextCard.vue`: “Nicht bekommen”, “In andere Liste
  übernehmen”, “Wieder offen”; `ListEntryView.vue`: “Von der Liste
  entfernen”), for keyboard and screen readers.
- **Package size in the row** (`ListRow` `packageSize`, also in templates):
  small and grey behind the name. With a size the name stays on one line
  and is shortened (ellipsis), so the row does not grow and the size stays
  visible; a very long size is cut at 40 % of the text width. Without a size
  the name may still take two lines.
- **Taking over** (`TransferSheet.vue`, design screen 07): products to
  take over (all preselected, deselectable), target list (other active
  lists with date and number of products, or “Neue Liste …” with a name,
  prefilled with the origin's name). The target list is loaded when
  chosen; products with another unit in the target lead to a second step
  per product: Behalten · Ersetzen · Beides. Then `takeOverEntries()` in
  `useLists.js`: a new list first (`list.create`), the `entry.takeOver`
  operations behind all entries of the target in the origin's order, then
  in the origin `entry.setStatus` notGot for open ones and
  `entry.transfer`. The origin row reads “nicht bekommen · nach REWE”
  (name from the overview, which the list view loads for that). Toast
  “„Gouda“ nach REWE übernommen · Menge addiert: 3 Becher”.
- **Completion**: “Einkauf abschließen” opens the same sheet with open and
  not-got entries and the extra target “Nicht übernehmen” (preselected: the
  offer is voluntary); without such entries only the text and
  “Abschließen”. After `list.complete` the app returns to the overview,
  toast “„Lidl“ abgeschlossen · 2 Produkte nach REWE” with “Wieder öffnen”.
- **Archive** (`ArchiveView.vue`, route `/archive`, “Listen” stays active
  in the navigation): reached from the overview by the row “Archiv · N
  abgeschlossene Einkäufe” below the cards (the overview remembers per
  device whether there is an archive, `pk.hasArchive.<user>`, so the row
  stands there while loading and “Neue Liste” does not move). Completed
  lists, last completed first, each with name and date, day of completion,
  “6 von 8 erledigt” and one status line (“2 nicht bekommen”, “1 nicht
  abgehakt” or “alles erledigt”, plus “nach Abschluss geändert”); rows have
  a fixed height, the skeleton the same. A completed list opens read-only
  (`ListRow` `readonly`: status as symbol, no handle, no checking; the
  product in the list context without actions) with a card “Abgeschlossen
  gestern” and the buttons “Wieder öffnen” (`list.reopen`) and “Neue Liste
  daraus”.
- **New list from an earlier purchase** (design screen 02): the sheet
  “Neue Liste” offers “Wie möchtest du starten?”: “Leere Liste” or one of
  the last five completed purchases (“Lidl · gestern”, number of
  products); with a purchase “Weiter” leads to the products (from
  “Neue Liste daraus” directly): all products in the old order with their
  quantities, preselected, “Alle” and “Nur nicht gekaufte”, “N von M
  ausgewählt”. `copyPlan()` takes each product once at its first place
  (equal units of a product listed twice are added up), without notes;
  `createListFrom()` sends `list.create` and `entry.add` with fresh keys in
  that order (`keysBetween`), all open. An empty name takes the
  purchase's name.
- **Adding:** a new entry gets quantity 1 and a key behind all entries
  (`appendKey`, also behind done and removed ones), so a fresh list follows
  the order of adding. A product that is already open on the list is not
  added twice; plus raises its quantity. The picker is described in
  [Adding products](#adding-products).
- **Sorting** only at the handle (`useDragSort.js`, Pointer Events, no
  library): the lifted row follows the finger with a shadow, the others
  move aside, a green dashed placeholder marks the target, the page scrolls
  at the top and bottom edge. During the drag only the DOM is changed
  (transforms), not Vue state; on release `planMove()` computes the new key
  (`entry.move`, usually one operation; if both neighbours share a key the
  right one moves first), and once the new order is rendered the row glides
  from the finger into its place. Arrow up/down on the focused handle move
  by one; a live region announces the new position. While a row is
  lifted the view shows the state of that moment (`frozen` in
  `ListView.vue`); changes of others that arrive meanwhile appear on
  release, and the drop is translated to the current state
  (`translateMove`: behind the same neighbour as on screen, else before the
  first remaining one after it; not at all if the entry is no longer open).
- **Changes of others** (live updates) do not make the list jump: the
  sections are `TransitionGroup`s with JS hooks, new rows open (height
  0 → full), rows that leave close, rows moved by somebody else glide to
  their place (`list-row--moving`); rows the view animates itself (own
  check, not got, reopen) are skipped. No glide right after an own drop.
  `prefers-reduced-motion` skips all of it.
- **Checking** on the check area: the row closes with a short height
  animation (the rows below follow evenly, nothing jumps), then
  `entry.setStatus` done with `seen`; toast “„Milch“ erledigt” with
  “Rückgängig”. Unchecking (toast or the check mark under “Erledigt”) opens
  the row again at its old place: the sort key never changes on checking.
  `prefers-reduced-motion` skips the animations.
- **Entry in the list context** (`ListEntryView.vue`): the product detail
  (`ProductDetail.vue`, slot `context`) with a card “In der Liste …”:
  quantity, note, done state; “Menge oder Notiz ändern” opens
  `EntrySheet.vue` (minus/plus in steps of 1, 50 for g/ml and 0.5 for
  kg/l, input with comma, unit from the fixed list, note up to 500
  characters “Gilt nur für diesen Einkauf.”); only changed fields are sent
  (`entry.set` with `base`). “Von der Liste entfernen” removes with
  “Rückgängig” (`entry.restore`). A product missing from the catalog is
  shown as “Unbekanntes Produkt”, the entry stays usable.
- **Skeletons** in end height: list rows 64 px (`SkeletonRow`), progress
  block 46 px, overview cards like the card and as many as last time; title and subtitle keep their
  height while the list name is loading.

## Quantity control

Concept in Obsidian “Piklis Konzept” › “Mengen, Reihenfolge und
Wiederherstellen”. One component for tile and row, also when editing a
template: `QuantityControl.vue` shows it, `useQuantityHold.js` is the gesture
(Pointer Events, no library), `steppedAmount()` and `quantityStep()` in
`utils/quantity.js` are the steps.

- **Hold:** 350 ms on one spot open a vertical scale next to the finger, on
  the side with more room. Moving 8 px earlier counts as scrolling or
  swiping and starts nothing, a scroll taken over by the browser ends the
  press (`pointercancel`).
- **Slide:** every 20 px up is one step more, down one less; releasing saves
  once and closes. At the end of the scale the reference point moves along,
  so turning back acts at once. A cancelled gesture saves nothing.
- **Without sliding** the control stays open as a dialog with −, +, “Details”
  and “Entfernen”; − and + act at once, a tap beside it closes it. For 300 ms
  after releasing it takes no taps, the click of the finished touch would
  otherwise land on it.
- **Steps:** 1 for packs and everything without a unit; 50 for g and ml, 0.5
  for kg and l, only when that unit was set on purpose. Rows stop at the
  smallest amount reachable above 0 (120 g → 20 g), tiles reach 0, which
  takes the product off. The same rule as in `EntrySheet.vue`.
- **Scrolling:** `touch-action` only counts from the start of a touch, so a
  non-passive `touchmove` blocks scrolling once the control has the finger.
- **Swiping:** the control calls `release()` of `useRowSwipe` when it takes
  over; rows that are swiped open do not start it.
- **Handle and check area:** marked `data-no-hold`, they start nothing.
- **iOS:** tiles and rows set `-webkit-touch-callout: none` and `user-select:
  none`, photos in them take no pointer events and no drag. The control and
  its backdrop use `touch-action: none`, otherwise quick taps on + zoom the
  page. The backdrop is a real button: Safari did not report taps on a plain
  element there.
- **Without a gesture:** a button “Menge” appears on keyboard focus and stays
  reachable for screen readers (`visually-hidden-focusable`) in tile and
  row. It opens the same dialog: `role="dialog"`, `ion-app` becomes `inert`,
  the quantity is a live region, Escape or “Schließen” close it, and the
  focus returns to the button.
- The control is teleported to `body` and closes on every route change,
  because Ionic keeps pages alive in the history.

## Input bar

Concept in Obsidian “Piklis Konzept” › “Neue Liste und Produktauswahl”,
`components/list/AddBar.vue`. In active lists the bar replaces the glass
navigation, the plus in the head and the dashed button; archived lists and
templates do not have it. The button in the empty state stays and leads to
the tiles. `AppPage` (`addBar`) keeps the space free on wide screens, too.

- **Suggestions** (`AddBarSuggestions.vue`): up to 20 from the catalog, list
  items never. Empty field: the most frequent products (`topProductIds()`, same
  weighting as the tiles). While typing: the hits of the catalog search. A
  product on the list shows “auf der Liste · 1 ×”, a done one “erledigt”.
- **Adding:** a suggestion adds exactly this product (`increaseProduct()`: open
  +1, otherwise a new open entry). Enter takes a catalog product only if the name
  is the same (`exactProduct()`: case, accents and punctuation do not count, as
  in `listItemNamed()`), otherwise `createProductOnList()` makes a list item; a
  partial hit is never chosen, “Zucker 1kg” stays the name. Everything goes
  through the queue, so it works offline. No toast, no question; a live region
  tells screen readers. A toast that is still open is closed when the bar opens,
  it would lie over the suggestions.
- **Keyboard stays open:** `@mousedown.prevent` on suggestions and buttons keeps
  the focus in the field, Enter does not leave it either.
- **Field:** `autocorrect="off"`, otherwise iOS replaces unknown names when they
  are entered; this also drops the row of word predictions, which gives the
  suggestions more room. `autocapitalize="sentences"`: names start with a capital.
  `:value` with `@input` instead of `v-model`, which waits for the end of a
  composition, so suggestions would lag on Android.
- **Variants B and C** (`addBarMode.js`), switchable with the button in the bar
  until Michael has chosen, B by default, also in the build. To remove the
  switch, leave only the chosen variant in `MODES`.
  - B: suggestions above the bar, best hit next to it. With the keyboard open
    the bar slides on top of it (`--keyboard-height`, `transform` over 250 ms).
    Tapping an input near the bottom makes iOS pan the whole page by the
    keyboard height, and fixed elements then no longer take taps where they are
    drawn. So a button lies over the field at rest and calls
    `focus({ preventScroll: true })`.
  - C: field and suggestions in a layer over the page. The field is always in the
    DOM: only an existing field gets the focus in the same tap, otherwise iOS
    does not open the keyboard.
- **Hardware keyboard:** nothing depends on the on-screen keyboard. On touch
  screens the suggestions of B wait up to 400 ms for `data-keyboard="open"`,
  because they would otherwise be too tall for a moment, and then appear anyway.
  Without an on-screen keyboard the bar stays at the bottom. Tab reaches
  suggestions and buttons, Escape closes.
- **Scanner:** `AddBarScanSheet.vue` runs the existing `BarcodeScanner`, or takes
  the number by hand (`BarcodeForm`, also the way out without a camera).
  - In the catalog, or a list item with that barcode on this list: on the list at
    once, open +1, also offline, no question (`productWithBarcode()`; leading zeros
    do not count, UPC-A may arrive as EAN-13).
  - Unknown and online: first `loadProducts()` (304 when nothing changed; a product
    somebody just added saves a request and a duplicate), then
    `GET /openfoodfacts/{barcode}`. A hit shows name and image (`FoodFactsHit`) with
    “Zur Liste”; a hit without a name asks for one.
  - Not found: name and an optional photo (`ProductImageField`). The barcode stays
    on the product (`POST /products` takes `barcode`), so the next scan finds it.
  - Offline: only a note.
  - “Im Katalog speichern” is on, because brands come back; off gives a list item
    with the same data. The product is created directly at the server
    (`createProduct`, stable id for a retry), goes on the list, and the sheet closes.
    `complete()` then adds the data (`applyFoodFacts`, everything but the name,
    `scanFields()`) and the image in the background; a toast appears only on failure.
  - Image without a crop step: `importFoodFactsImage()` uses `squareImage()`: padded
    to a white square like before the crop dialog, the whole square as crop and as
    original, so “Neu zuschneiden” works later.
- **Tiles:** the button opens `/lists/:id/add`.
- `position: fixed` refers to the Ionic page here (`contain`), so the bar is
  centred next to the sidebar without an offset.

## Adding products

Concept in Obsidian “Piklis Konzept” › “Neue Liste und Produktauswahl”,
decisions “Produktauswahl und Übernahme”; design package screens 08 and 09.
`ListAddView.vue`, route `/lists/:id/add`.

- **Opens without keyboard:** nothing is focused; the search field only
  gets the focus (and the keyboard) when tapped.
- **Sticky head:** back, title and search field stay at the top while
  scrolling and with the keyboard open (`stickyHead` and the `head` slot of
  `AppPage`), also when editing a template. Changing the search scrolls back
  to the top, where the hits are.
- **Tiles** (`AddTile.vue`): the number of columns is the single token
  `--tile-columns` (3, on every width). The grid gives each row the height of
  its tallest tile, from 80 px, and every tile fills its row. The whole tile is one button: the first
  tap sets quantity 1, every further tap adds 1, a badge shows the quantity
  (weights with their unit). Holding opens the
  [quantity control](#quantity-control); “Details” goes to the entry
  (`/lists/:id/entries/:entryId` with quantity and note) or to
  `/products/:id`. Nothing on the page changes size while tapping: the tile
  keeps its height, the subtitle “35 ausgewählt · Lidl” stays on one line.
- **What is shown:** products that are open on the list when the view opens
  are hidden, the line above the tiles says how many (“3 schon ausgewählte
  ausgeblendet”). Tiles tapped here stay in place until the view is left,
  done and not-got products stay visible with their hint. A hidden product
  comes back if its entry stops being open meanwhile (somebody else checked
  it). The search ignores the hiding: it shows open products with their
  badge, otherwise “neu anlegen” would invite duplicates.
- **No duplicates:** `setProductQuantity(listId, productId, amount)` in
  `useLists.js` sets the quantity of the open entry, adds one
  (`entry.add`) or removes it; it acts on the display state at once, also
  for several taps in the same tick. Done entries do not count as “on the
  list” (decision from step 2): their tile is unselected and shows
  “erledigt”, a tap adds a second entry (“noch mal Milch”), which the tile
  then shows with its quantity. Not-got entries behave the same (tile
  “nicht bekommen”, a tap adds a new open entry; decision of step 7).
- **Order** (`utils/pickerOrder.js`): products created here (section
  “Neu angelegt”), then the suggestions (“Häufig gekauft”), then the rest
  of the catalog alphabetically (“Weitere Produkte”, without suggestions
  “Alle Produkte”). The order is frozen when list, catalog and suggestions
  are loaded and stays while the view is open (also after visiting a
  product and coming back); products created by others meanwhile go to the
  end, hidden ones drop out.
- **Loading more:** 48 tiles first, 24 more whenever an invisible strip
  over the last 150 vh of the tiles comes into view (`IntersectionObserver`).
  The catalog itself is already loaded completely (`GET /products`), so
  “loading more” renders more tiles; no extra request.
- **Suggestions** (`GET /products/suggestions`,
  `Products\SuggestionRanking`): every list the user can access in which
  the product appeared in the last 90 days counts once (entries not
  removed, lists not deleted, products not hidden); time of use = when it
  was checked for done entries, else when it was added. Each list weighs
  1 plus a recency bonus falling linearly from 0.5 (today) to 0 (90 days):
  frequency decides, recency breaks near ties (three times two months ago
  ≈ 3.3 beats twice yesterday ≈ 3.0). Ties: last used first, then product
  ID. Answer `{ days, suggestions: [{ productId, score, uses, lastUsedAt }] }`
  without names; the app takes them from the catalog. If the request
  fails, the picker shows the catalog alphabetically.
- **Filter “Nur nicht ausgewählte”:** hides the products that were
  selected when the filter was switched on; tiles selected afterwards stay
  where they are, so a quick second tap still hits the same product. Works
  in the search, too.
- **Search** in the loaded catalog (`utils/productSearch.js`, up to 60
  hits as tiles, “N Treffer”); at the end always “„Begriff“ neu anlegen”:
  `createProductOnList()` queues `product.create` with `listItem: true` and
  then `entry.add` (quantity 1), shows the list item at once
  (`addLocalProduct`, replaced by the server's product, removed again with a
  toast on rejection), clears the field and keeps the focus for the next
  name. Names up to 120 characters. A list item of the same name already on
  the list counts instead of a new one: open +1, done or not got a new open
  entry. Its tile stays under “Neu angelegt” while the view is open. In a
  template the same button still creates a catalog product.
- **Skeleton:** 8 tiles and the section label in end height (tiles start
  at the same offset as the loaded ones).
- **“Fertig”** goes back to the list.

## Household

Concept in Obsidian “Piklis Konzept” › “Persönlicher Start und Haushalt” and
“Haushaltsrollen”. Rules in `Households\HouseholdService` (no HTTP, no
database; tests with an in-memory store), REST in `Rest\HouseholdEndpoint`,
view `views/HouseholdView.vue` (user menu › Haushalt).

- **Start:** nobody needs a household. Without one, new lists are personal.
- **Create:** name 1–100 characters; the creator becomes admin and gets an
  invite code at once. At most one household per person (`already_member`).
- **Invite code:** 8 characters without look-alikes (`Households\InviteCode`),
  shown as `ABCD-EFGH`, input ignores case, spaces and dashes; valid 7 days
  and reusable (several people can join with it); admins renew it, the old
  one stops working. Only admins see it. The app offers “Teilen” (share
  sheet of the device, text with the code) where available, and “Kopieren”.
- **Join:** with the code as member. Already in another household:
  `already_member`; the app offers “Anderem Haushalt beitreten”, which sends
  `switch: true` (leave by the rules below, then join). Same household:
  `same_household`. Unknown or expired code: `invalid_code`.
- **Roles:** all members edit the household's lists; admins renew the code,
  appoint or demote admins and remove members (also other admins), and
  dissolve. There is always at least one admin: the last admin cannot step
  down or leave while others are members (`last_admin`); they first make
  someone else admin or dissolve.
- **Leave, switch, dissolve:** household lists stay in the household. Before
  leaving the app offers to copy single household lists as personal lists
  (`HouseholdLeaveSheet.vue`, nothing preselected; the copies are made
  before leaving). The last member leaving (also by switching) deletes the
  household: the server wants `confirmDissolve: true`, else
  `confirm_dissolve`. Dissolving (admins) deletes household and memberships
  and gives its lists and templates a tombstone (`list_deleted` for late
  operations). The leave sheet also offers the household's templates as
  personal copies (`POST /templates/{id}/copy` `to: personal`, before
  leaving) and says how many templates a dissolution deletes.
- **After a change of membership** the app drops the loaded lists and loads
  the overview again (`reloadAfterHouseholdChange()`); the session gets the
  new summary (`setSessionHousehold`).
- **New lists:** with a household the sheet “Neue Liste” asks “Für wen?”:
  household (default) or “Nur für mich”. A new list from taking over follows
  the origin list. Existing personal lists stay private; “Liste bearbeiten”
  offers “In den Haushalt verschieben” (confirmation in the sheet) and “In
  den Haushalt kopieren”. Household lists show “Gehört dem Haushalt …”.
- **Origin** in the overview and the list subtitle: “nur ich” or “Haushalt”.
- **Suggestions** in “Produkte hinzufügen” count the household lists and the
  lists shared with the user, too (every accessible purchase).
- **Skeleton:** name and role come from `GET /me`, so only the members wait;
  as many placeholder rows as last time on this device
  (`pk.householdMembers.<user>`) and an invite card of the same height.

## Sharing single lists

Concept in Obsidian “Piklis Konzept” › “Einzelne Listen mit Außenstehenden
teilen”. Rules in `Lists\ListSharing` (no HTTP, no database; tests with
in-memory stores in `tests/unit/Lists/ListSharingTest.php`), storage
`Lists\ShareRepository`, REST `Rest\SharesEndpoint`, sheet
`components/lists/ListShareSheet.vue`, view `views/InviteView.vue`.

- **Who shares:** the owner of a personal list and every member of the
  household a list belongs to (`ListPermissions::canManageSharing`). Guests
  get `403 forbidden` for creating links, seeing or removing participants,
  copying and moving; `list.update` and `list.delete` are `rejected
  forbidden` in `POST /ops`.
- **Link:** `POST /lists/{id}/invites` creates a token of 32 random bytes
  (`Lists\InviteToken`, base64url, 43 characters); the server stores only
  its SHA-256 hash, so the token exists only in this answer. Valid 7 days,
  usable once. The app builds the address from its own location
  (`location.origin + pathname + '#/invite/<token>'`), so it points to the
  instance, in the dev server to the dev server.
- **Passing it on:** “Link erstellen und teilen” opens the share sheet of the
  device (`navigator.share`, text “Michael lädt dich zur Einkaufsliste … ein”
  plus the address), without it the link is copied. If the browser refuses
  because the tap is over (Safari after the request), the link stays in the
  sheet with “Teilen” and “Kopieren”.
- **Accepting:** `/invite/:token` needs a session; without one the router
  goes to the login with `?redirect=/invite/…` and comes back after the
  login, the login explains that an existing account is needed (no account
  is created through the link). `GET /invites/{token}` shows who invites,
  name and date of the list and `access` (own role or null);
  `POST /invites/{token}/accept` adds the share row and marks the link used
  (`used_at`, `used_by`) in one transaction with the invite row locked.
  Someone who already has access (owner, member, guest, also the guest who
  used this link) gets `already` and the list opens; the link is not used
  up.
- **Invalid links:** `invite_invalid` with `reason`: `unknown` (404, also
  withdrawn), `used`, `expired`, `list_deleted`, `revoked` (the creator no
  longer may share, e.g. left the household) (410). The app shows for all
  “Link nicht mehr gültig, bitte um einen neuen”.
- **Participants:** `GET /lists/{id}/shares` (owner, members): accepted
  shares with name, avatar, who invited and since when, and the open links
  (not used, not expired) without token. “Entfernen” (`DELETE
  /lists/{id}/shares/{userId}`, confirmation in the sheet) revokes;
  “Zurückziehen” (`DELETE /lists/{id}/invites/{inviteId}`, id = hash)
  withdraws an open link.
- **Guests:** origin chip “geteilt” (share symbol, lilac) in the overview and
  “Geteilt von Michael” in the list header. “…” opens “Geteilte Liste”: who
  shared, what a guest may do, and “Liste verlassen” (deletes the own share
  row, confirmation). Rights as in [Ownership and roles](#ownership-and-roles);
  product rights stay those of the user. A new list from “In andere Liste
  übernehmen” of a shared list belongs where the guest's new lists go (own
  household or personal), not to the foreign household.
- **Only this list:** a share adds just this list to `GET /lists`, the
  suggestions and the archive (when completed); other lists, templates and
  histories of the household stay invisible.
- **Revoked or deleted while open:** the list's poll answers `403 no_access`
  or `410 list_deleted`; the view shows “Kein Zugriff mehr” or “Liste
  gelöscht” and the list leaves the overview at once.

## Templates

Concept in Obsidian “Piklis Konzept” › “Vorlagen”. Not designed in the design
package; the views follow the list views. Rules in `Templates\TemplateService`
(no HTTP, no database; tests with an in-memory store in
`tests/unit/Templates/`), storage `Templates\TemplateRepository`, REST
`Rest\TemplatesEndpoint`, state `composables/useTemplates.js`.

- **Online, saved as a whole** (decision of step 2): name and all products
  (quantity, note, order) go in one `PUT /templates/{id}` with
  `If-Match: "<revision>"` of the version the draft started from. Another
  save in between: `409 template_changed` with the current template; the app
  asks “Inzwischen geändert” (Andere Fassung · Meine Fassung, the latter
  saves again on top of the current revision). Without `If-Match`: `428
  precondition_required`. The server gives fresh sort keys in the order sent
  (`SortKey::manyBetween`); a product keeps its entry ID across saves.
  Moving and deleting count the revision up too. No live updates: overview
  and template load when opened.
- **Rights:** owner of a personal template, every member of the household of
  a household template (see, use, edit, delete). No shares, guests see
  nothing (`403 no_access`). New templates belong to the household by
  default when there is one (“Für wen?”), like lists.
- **Save a list as template** (`TemplateSaveSheet.vue`, from “Liste
  bearbeiten” and from an archived list, not for guests): name (the list's
  name preselected), “Für wen?”, and only if the list has notes
  “Einkaufsnotizen mitspeichern” (off). Second step “Produkte prüfen”: all
  visible entries in list order, done and not-got ones included, each
  product once (`templatePlan`, equal units added up like `copyPlan`), all
  preselected, “Alle” · “Keine”. `POST /templates` with the browser's UUID;
  toast with “Ansehen”.
- **Overview** (`TemplatesView.vue`, `/templates`): one section, sorted by
  name; cards like the list overview (`ListCard` `variant="template"`:
  name, origin chip, “7 Produkte”, first four products and “+ N”).
  Skeleton cards as many as last time on this device
  (`pk.templateCount.<user>`). “Neue Vorlage” creates an empty template
  (name, “Für wen?”) and opens it in edit mode.
- **Template** (`TemplateView.vue`, `/templates/:id`): card with “Zuletzt
  gespeichert von Ewa · heute”, “Neue Liste daraus” and “Vorlage
  bearbeiten”; rows read-only (`ListRow` `readonly`, `trailing="none"`),
  name or image open the product. “…” opens “Vorlage verwalten”
  (`TemplateFormSheet.vue`): rename, move a personal template into the
  household (confirmation in the sheet) or copy it there, delete.
- **Edit mode** (explicit, concept: lasting changes only through “Vorlage
  bearbeiten”): a draft in `useTemplates` (survives the trip to the picker
  and back), name field, sorting at the handle (`useDragSort`, arrow keys),
  quantity and note in `EntrySheet`, remove with “Rückgängig” (`ListRow`
  `trailing="remove"`), dashed “Produkte hinzufügen” to the picker
  (`ListAddView.vue` on `/templates/:id/add`: plus and minus change only
  the draft; no “erledigt”; “neu anlegen” queues
  `product.create` and puts the product into the draft). “Speichern” in the
  header and at the bottom; “Verwerfen” asks when something changed. The
  sortable rows share the global drag styles (`sort-rows` in
  `styles/base.css`).
- **New list from a template:** “Neue Liste” › “Wie möchtest du starten?”
  lists the templates (group “Aus Vorlage”, before the earlier purchases);
  “Neue Liste daraus” starts directly in the second step. All products
  preselected and deselectable, notes of the template are taken along.
  `createListFrom()` sends `list.create` and `entry.add` with fresh keys in
  the template's order: the list is independent, changing or removing
  entries never touches the template.
- **Household:** dissolving tombstones the household's templates
  (`HouseholdRepository::delete`); leaving offers personal copies.

## Product catalog

One catalog for all signed-in users (Obsidian “Piklis Konzept” ›
“Produktkatalog”). PHP in `Products/`, REST in `Rest\ProductsEndpoint`, view
`views/ProductsView.vue` with `ProductDetailView.vue` and `ProductEditView.vue`.

- **Product** (`Products\Product`, JSON camelCase through
  `Products\ProductPresenter`): `id`, `name`, `category`, `icon`,
  `description`, `packageSize`, `brand`, `barcode`, `imageUrl` (128 px or
  null), `image` (`{ small, medium, large, original }` or null, see
  [Product images](#product-images)), `imageSource`, `foodFacts`
  (`{ fetchedAt, language, ingredients, nutrition, url }` or null, see
  [Open Food Facts](#open-food-facts)), `listItem`, `hidden`, `hiddenAt`,
  `createdBy`, `createdAt`, `updatedAt`.
- **Input** (`Products\ProductInput`): only `name` is required (1–120
  characters, whitespace collapsed); `category` one of the fixed keys or
  null; `icon` a sprite key (`a-z0-9-`), null or `allgemein` = general icon
  (stored as null; the server does not know the sprite, unknown keys show the
  general icon); `description` up to 2000, `packageSize` up to 50, `brand`
  up to 120 characters;
  empty optional fields become null. `400 invalid_product` names `field` and
  `reason` (`required`, `too_long`, `invalid`).
- **Rights** (`Products\ProductPermissions`, same rule in
  `useProducts.canEditProduct()`): everybody creates products; the creator
  edits their own as long as it is not hidden; `piklis.products.manage` edits
  all and hides or shows them again. A list share never grants rights on
  the catalog; list items have their own rule, see [List items](#list-items).
  The app offers only what is allowed, the server decides (`403
  forbidden`). The right is checked before the input.
- **Categories:** fixed keys in `Products\Categories` and
  `frontend/products/categories.js` (checked against
  `tests/fixtures/categories.json`): `fruit`, `vegetables`, `bread`, `dairy`,
  `cheese`, `meat`, `fish`, `grains`, `canned`, `frozen`, `sweets`, `drinks`,
  `coffee_tea`, `baking`, `drugstore`, `household`, `baby`, `pets`, `other`.
  Names in `i18n` (`categories.<key>`); colours only as tokens.
- **Fallback icons:** 97 product types in `data/icons.json`, each with the
  category it is listed under in the icon picker (sheet, searchable by icon
  name, category name or keyword such as “Joghurt”, grouped and coloured by
  category). The general icon `allgemein` is the default. Details in
  [Fallback icons and category colours](#fallback-icons-and-category-colours).
- **Suggestion** (`Products\ProductSuggestion`, JS `products/suggest.js`,
  same rule, cases in `tests/fixtures/product-suggestions.json`): category
  and icon from the name via the keyword list `data/keywords.json`, no AI and
  no service. Names and keywords are normalised like the search. A word
  matches when it ends with a keyword, also with an ending (`n`, `en`, `e`,
  `s`, `es`, `er`, `nen`, `ern`): “Vollkornbrot” → brot, “Bananen” → banane.
  Inside a word the longest keyword wins; across words the first word with a
  match (“Joghurt Erdbeere” → Joghurt). `=` marks whole-word keywords
  (“=ei” matches “Eier”, not “Brei”). Without any word end a word start of at
  least 4 letters counts (“Hähnchenbrustfilet”). Nothing matches: no
  suggestion, general icon, no category. The form suggests while typing until
  category or icon is chosen by hand (hint “Vorschlag aus dem Namen”); quick
  create in the picker sends the suggestion; the server fills `category` and
  `icon` from the name only when the fields are missing, a sent `null` stays.
- **Base catalog** (`data/catalog.json`, `Setup\CatalogSeeder`, event
  `onPackageSetup`): 152 common products with category and icon, created
  once by the package setup. Once: as soon as one catalog product exists
  (also hidden), the setup creates nothing; products are never deleted, so
  hidden or renamed ones do not come back. Never overwriting: an entry whose
  ID or normalised name already exists is skipped. Owner: `created_by =
  'catalog'`, no user, so only product maintainers edit these products and
  they are nobody's “Von mir”. Fixed UUIDs in the data set.
- **Search** (`Products\SearchText`, `Products\ProductSearch`, JS
  `utils/productSearch.js`, cases in `tests/fixtures/product-search.json`):
  text in lower case without accents, `ß` → `ss`, `ł` → `l`, everything but
  letters and digits separates words. Every search word must occur in the
  name, also inside a word (“brot” finds “Vollkornbrot”). Order: same name ·
  name starts with the input · every word starts a word · other hits;
  then alphabetically (byte order of the normalised name, then ID). Hidden
  products only on request (maintainers). “kaese” does not find “Käse”
  (no transliteration of umlauts).
- **Suggestions while typing** come from the loaded catalog in the browser
  (`useProducts.search()`), so they are instant and work offline later;
  `GET /products/search` gives the same order on the server. Similar names
  are never merged: the list always offers “„…“ neu anlegen”, the form shows
  “Schon im Katalog” with the existing products to open instead.
- **View:** search field (placeholder with the number of products), filter
  Alle · Von mir · Ausgeblendet (maintainers), rows with image or icon, name
  and category · package size (64 px like list rows, skeleton rows of the
  same height); while searching the subtitle shows the number of hits. Phone:
  a row opens the detail page, “+” the form page. Desktop (from `64em`):
  product maintenance in two columns like design screen 11, list on the left,
  detail or form on the right (sticky) without changing the page.
- **Detail:** large image or icon (`hero`), category chip, name, brand ·
  package size, description, “Ausgeblendet” with symbol and text;
  ingredients, nutrition and source from Open Food Facts; buttons only when
  allowed (Bearbeiten; Ausblenden or Wieder einblenden for maintainers, with
  undo in the toast; “Mit Open Food Facts ergänzen”), otherwise a hint who
  may edit.

## List items

Concept in Obsidian “Piklis Konzept” › “Listenartikel”. Not every line of a
list belongs in the catalog (“Lichterkette”), so what is typed in a list
becomes a list item: a row in `pk_products` with `list_item = 1`. Image,
description, Open Food Facts, sync and offline work as for any product, and
taking it into the catalog only clears the mark.

- **Creating:** `product.create` and `POST /products` take `listItem: true`, and
  `barcode` for what the scanner creates (checked like every barcode, an invalid one
  is `invalid_product` with `field: barcode`; updating ignores it).
  Only creating sets the mark; `PATCH` never changes it.
- **Never in the catalog:** `GET /products`, `GET /products/search`,
  `GET /products/suggestions` and the picker leave list items out, also for
  product maintainers.
- **How they reach the app:** lists only carry `productId`; names and images
  come from the catalog, which has no list items. So they come with what uses
  them (`Rest\ListItems`): `products` in `GET /lists/{id}`, `products` in a
  template and, for the previews, in `GET /templates`. A list always sends all
  its list items, also “since N”, because editing one adds no revision to any
  list; the next poll brings it (5 s). The ETag covers them.
- **In the app:** `useProducts` keeps them apart from the catalog in
  `listItems` (`rememberListItems`), so `products`, `catalog` and `search` stay
  the catalog; `findProduct` finds both. A known list item that a list no longer
  sends was taken into the catalog: the app reloads the catalog and drops it.
- **Entry sheet:** `EntrySheet.vue` shows photo and description of a list item
  next to quantity and note (`listItemFields`, only in `ListEntryView`: a template
  draft saves at the end, a product change would not wait for that). Both go to
  the REST API on “Speichern”; offline, or while the server does not know the
  item yet, a hint stands there instead. An error keeps the sheet open. Catalog
  products keep both under “Bearbeiten”, where the maintenance rights apply.
- **Offline:** stored with the list (`list:<id>`, `products`) and with
  orphaned changes (`orphan:<id>`), restored at start; a list item leaves the
  device with the last list that used it. Their images are fetched like those
  of catalog products. Creating works offline, editing goes through the REST
  API like all product changes and needs a connection.
- **Visible** (`ProductStore::canAccessListItem`): for everybody with access to
  a list with a visible entry of it, or to a template that has it. The creator
  too, while nothing uses it yet (`product.create` arrives before `entry.add`).
  A removed entry or a deleted list gives no access. Everybody else, also
  product maintainers, gets `404 product_not_found` on all routes under
  `/products/{id}` (`ProductService::loadVisible`).
- **Rights:** whoever sees it edits it, also guests: data, image, Open Food
  Facts. Hiding is `403 forbidden`, it is not in the catalog.
- **Used by reference:** taking over, “Aus früherem Einkauf”, copies and
  templates keep the same `productId`, so it is the same row on every list:
  editing it changes it everywhere, and access follows each use. `entry.add`
  and templates do not check the access: a v4 UUID is only known to someone
  who had access, and “Als eigene Liste speichern” must still work after a
  share was revoked.
- **Into the catalog:** `POST /products/{id}/catalog` for everybody who sees
  it; on a catalog product it changes nothing. Afterwards the normal rights
  apply, `createdBy` stays. `EntrySheet.vue` shows it in `EntryCatalog.vue`
  (lists and templates): the button “In den Katalog aufnehmen” for a list
  item, “Schon im Katalog” for a catalog product and after taking it over. A
  list item whose name is exactly that of a catalog product shows the hint and
  keeps the button, because nothing is merged automatically. Offline the sheet
  says “Das geht nur mit Verbindung.”
- **Cleaning up** (`Products\ListItemCleanup`, cron and
  `./console pk:clean-list-items`, daily at 03:30):
  - The image (crop and original) goes 90 days after the completion of the
    last list using the item, unless a template uses it or a list is active.
    Name and fallback icon stay in the archive.
  - The row goes, with its image, once no visible entry on a list that is not
    deleted and no template that is not deleted uses it, one day after its last
    change, so an `entry.add` on its way is safe.
  - What counts is `Products\ListItemUsage`, from `listItemUsages()`, which
    reads all list items at once. Fine for now; page it if there are many.

## Open Food Facts

Optional completion of a product (Obsidian “Piklis Konzept” › “Open Food
Facts”). PHP in `FoodFacts/` and `Products\ProductFoodFactsService`, REST
in `Rest\FoodFactsEndpoint` and `Rest\ProductsEndpoint`, app in
`components/products/FoodFactsSheet.vue`, `FoodFactsDetails.vue`,
`BarcodeScanner.vue`, `utils/foodFacts.js`, `utils/barcode.js`.

- **Server only:** the app never talks to Open Food Facts; the server asks
  `https://world.openfoodfacts.org/api/v3.4/product/{barcode}` with a fixed
  field list (`OpenFoodFactsClient::FIELDS`). API v3.4 is pinned: v3.5
  changes the nutrition structure and is still in development, Open Food
  Facts serves older versions in their old form. No account, no key; the
  user agent is `Piklis/1.0 (private shopping list app)` (app name as asked
  by Open Food Facts, no person's name or e-mail address). cURL with HTTPS
  only, no redirects (a redirect to another database counts as not found),
  connect 10 s, total 30 s (the image server was slow in tests).
- **Barcode** (`FoodFacts\Barcode`, JS `utils/barcode.js`, shared cases in
  `tests/fixtures/barcodes.json`): digits only, spaces and hyphens removed,
  8, 12, 13 or 14 digits, the GTIN check digit must match. Typing errors
  are caught before asking Open Food Facts (`400 invalid_barcode` with
  `reason` `required` · `invalid` · `checksum`).
- **Cache** (`pk_off_cache`, `FoodFacts\FoodFactsLookup`): one row per
  barcode with the reduced answer (`OpenFoodFactsClient::compact()`) or
  null for not found. Hits stay 7 days, not found 1 day; “Neu abrufen”
  (`?refresh=1`) asks again unless the last fetch is younger than 10
  minutes. Brake: at most 10 fetches per minute for everybody (Open Food
  Facts allows 15 product reads per minute and IP); above, an older cache
  row helps, otherwise `429 off_busy`. Unreachable (`503
  off_unavailable`): an older row helps, except on refresh.
- **Hit** (`FoodFacts\FoodFactsProduct`): name `product_name_de` ·
  `_en` · `product_name` (names are often language neutral, at most 120
  characters); brand `brands` without duplicates; package size from
  `product_quantity` + unit (“1000 ml” → “1 l”), otherwise the cleaned
  `quantity` text (`PackageSize`); ingredients only German or English;
  nutrition (`Nutrition`): the EU label rows energy kJ/kcal, fat, saturated
  fat, carbohydrates, sugars, fibre, protein, salt, each `per100` and
  `perServing` from `_100g`/`_serving`, `per` `ml` when Open Food Facts says
  `100ml` or the product is filled in ml, otherwise `g`, `servingSize` only
  with serving values; nothing is computed or estimated
  (`nutriments_estimated` is ignored); image: selected front image (de · en
  · main language · first), 400 px for the preview, `full` for taking over.
  Missing data is null.
- **Taking over** (`POST /products/{id}/openfoodfacts` with `{ barcode,
  fields }`): the right to edit first, then the fields
  (`Product::FOOD_FACTS_FIELDS`), the values always from the server cache,
  never from the app. Chosen fields with a value replace, chosen fields
  without a value and unchosen fields stay as they are; the description is
  never touched. The barcode and fetch date are always stored (the source of
  the data). `DELETE …/openfoodfacts` unlinks: barcode, ingredients and
  nutrition go, taken over name, brand, size and image stay.
- **Image:** the app loads the full image through
  `GET /openfoodfacts/{barcode}/image?size=full` (only the cached front
  image on images.openfoodfacts.org, no free URLs), places it centred on a
  white square (`padToSquare`, tall packages stay whole), the user crops it
  in the normal crop dialog and it is uploaded like an own photo with
  `source=openfoodfacts` (`image_source`). Recropping keeps the source, an
  own photo or removing clears it.
- **Shared parts:** `BarcodeForm`, `FoodFactsHit` (also the skeleton) and
  `FoodFactsMessage`, used here and in the scan sheet of the input bar.
- **Sheet:** offline a clear message instead of the input; input with
  numeric keyboard and check digit hint; scan; hit with preview, then one
  checkbox per available field with what it does (neu · schon gleich ·
  ersetzt „…“). Preselected only what is empty or equal, plus ingredients
  and nutrition; the name never (a product always has one), an own photo
  never. Fields Open Food Facts lacks are named and stay empty.
- **Scanner:** the browser's `BarcodeDetector` when it reads EAN/UPC
  (Chrome on Android, macOS); otherwise (Safari, iPhone) `barcode-detector`
  with the zxing-wasm reader. Both are loaded only when scanning
  (`import('@/utils/barcodeScanner.js')`); the WebAssembly file
  (`zxing_reader.wasm`, 0.95 MB, gzip 0.41 MB; JS chunk 43 KB, gzip
  15 KB) comes from `bin/app/assets/`, never from a CDN (`locateFile`
  override), and is not precached (`globIgnores` for the scanner chunks). The
  first scan puts them into the runtime cache `pk-scanner` (CacheFirst, hashed
  names), so catalog barcodes scan offline afterwards. Before that first scan the
  scanner cannot start offline; “Nummer eingeben” still works.
  Rear camera, a code counts after two equal reads with a valid check digit.
- **Detail:** brand next to the package size; ingredients and nutrition in
  `<details>` (closed), nutrition as a table with “je 100 g/ml” and, with
  serving values, “je Portion (…)”, energy in one row (“267 kJ / 64 kcal”),
  “–” for missing values. Source line with fetch date, link to the product
  at Open Food Facts, ODbL for data and CC BY-SA for an image taken over;
  editors get “Neu abrufen” and “Verknüpfung lösen”. The data travels with
  the catalog into the local store, so the detail shows it offline.
- **Tests:** PHPUnit with stored Open Food Facts answers in
  `tests/fixtures/openfoodfacts/` (complete milk, sparse product, product
  with serving values, not found), no live requests.

## Fallback icons and category colours

- **Sprite:** `frontend/icons/ersatzicons.svg`, generated by
  `npm run icons` (`build/make-sprite.mjs`) from `data/icons.json`, embedded
  once at start (`icons/sprite.js`). One source per product type: Lucide
  (`@lucide/vue`, ISC, notice in `LICENSE-lucide.txt` and in the sprite) where
  a fitting icon exists (49), the design package's icons for gaps
  (`build/icons/design.svg`, 11; its apple, banana, milk, eggs and carrot are
  replaced by Lucide), the rest drawn in the same style (`build/icons/own.svg`,
  37). All symbols get the same stroke (24 grid, line 1.75, round caps and
  joins, `currentColor`). Lucide fills the grid (2–22), the others about
  3.5–20.5, so Lucide shapes are scaled to 90 % around the centre with the
  stroke compensated (still 1.75 visible).
- **Overview:** `/#/icons` (dev server only, also in the user menu) shows all
  icons grouped by category in their colours with name and source.
- **Category colours:** ten pastel families plus neutral as tokens
  `--cat-<family>-bg` (surface) and `--cat-<family>-line` (line), light and
  dark, in `styles/tokens.css`. `[data-category="…"]` maps a category to a
  family (`--category-bg`, `--category-line`); the same mapping is
  `CATEGORY_COLORS` in `products/categories.js` (test compares both).
  Fruit green; vegetables and canned orange; bread, grains and baking sand;
  dairy and cheese yellow; meat rose; fish blue; frozen ice; sweets pink;
  drinks and coffee/tea teal; drugstore lilac; household slate; baby, pets,
  other and products without category neutral (`--color-surface-2`,
  `--color-icon`). Line to surface 4.9–5.9 : 1 light, 7.4–8.2 : 1 dark (test
  checks ≥ 3 : 1). Much weaker than the status colours, which also carry a
  symbol and text; done rows show the icon neutral and muted.
- **Where:** `ProductImage` takes `category` and sets `data-category`; the
  add tile carries it on the tile as well. Lists, cards, checklists, picker,
  catalog rows, detail and form pass `categoryOf(product)`.

## Product images

Obsidian “Piklis Konzept” › “Produktkatalog” › “Bilder und Zuschnitt” and
“Piklis Entscheidungen” › “Produktbilder”. Model for the crop: the user
picture of SmartPicker.

- **In the browser** (`ProductImageField.vue` in the product form): take a
  photo (`capture`, phones), choose a file, or paste a copied image on the
  desktop (⌘ V / Strg + V anywhere in the form, or the button “Einfügen”
  with `navigator.clipboard.read()`). Before cropping, the file is checked
  against `GET /config` (`maxBytes` from `media_maxUploadFileSize`; JPEG,
  PNG, WebP; Safari delivers HEIC as JPEG); too large files show the
  size and the limit.
- **Crop** (`ImageCropDialog.vue`, `utils/imageCrop.js`): square viewport,
  move by dragging, zoom with two fingers, wheel, slider or buttons, keys
  (arrows, plus/minus); full screen on phones, centred from `45em`.
  “Übernehmen” draws the crop with exactly `cropSize` (1600) px and the
  whole image reduced to `maxPixels` (`media_maxUploadSize`, 2048) as JPEG
  (`utils/imageEncode.js`). Re-encoding drops EXIF with location and camera
  data; browsers apply the EXIF orientation when drawing.
- **Saving**: the form keeps the change (new image, removal) until
  “Speichern”, then sends the product data first and the image after it
  (`POST /products/{id}/image` multipart: `image` = crop, `original`
  optional; `DELETE /products/{id}/image`). A failed image upload leaves
  the saved product and shows a toast.
- **Re-crop**: loads `image.original` (JPEG from the media cache) and sends
  only a new crop; the original stays.
- **Storage** (`Images\QuiqqerImageMedia`): QUIQQER media of the standard
  project `piklis`, folder “Produktbilder”, originals in
  “Produktbilder/Originale”, created by the system user, file names are
  random UUIDs (v4) per upload (new address, no stale browser cache). No
  own image cache.
- **Rules** (`Products\ProductImageService`): rights like the product data
  (`ProductPermissions::canChangeImage`); checks first
  (`Images\UploadedImage`: crop JPEG 1600 × 1600, original JPEG at most
  `maxPixels`, both at most `maxBytes`), then store the new media, save
  the product, delete the old media (replace: crop and original; re-crop:
  only the crop; remove: both). If saving fails, the new media are deleted
  again. Deleting also removes the cache files and the WebP files that
  `Image::deleteCache()` misses (`quiqqer/cache` 3.6 writes one next to the
  source in `media/sites/` when resizing).
- **Addresses** (`Images\ProductImageUrls`): `<cache>/Produktbilder/<uuid>__128x128.webp`,
  `__640x640.webp`, `__1600x1600.webp` (list and tile · detail · large
  view) and the original as `<cache>/Produktbilder/Originale/<uuid>.jpg`.
  Because the crop always has 1600 px, the names are fixed and equal to
  the core's `Image::getSizeCachePath()` (integration test). One query
  reads the file paths of all images of a response.
- **WebP from the media cache**: a missing `.webp` goes through
  `index.php` (`onRequestImageNotFound` of `quiqqer/cache`), which creates
  the size and converts it with `cwebp`; afterwards Apache serves the file
  directly (no PHP). Requires `media_maxUploadSize` ≥ 1600 and
  `quiqqer/cache` with `webp=1` (HTML cache, JS cache and lazy loading
  off, see docs/development.md).
- **Protection**: whoever knows an address sees the image without login
  (decision “Schutz”). The service worker caches the 128 and 640 px sizes
  for offline use (see [Offline](#offline)).
- **Display**: `ProductImage` takes `imageUrl` (128 px) in rows and tiles,
  `image.medium` in the detail; done rows show photos with an overlay
  (`muted`). The detail opens the large view (`ImageViewer.vue`,
  `image.large`): dark full screen, zoom 1–4× with two fingers, wheel,
  double tap or buttons, panning while zoomed, Escape closes.

## REST API

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/me` | own user: `id`, `name`, `initials` (first and last name), `avatarUrl`, `settings.theme`, `permissions.manageProducts`, `household` (`{ id, name, role }` or null) |
| `PATCH` | `/me/settings` | `theme` (`auto`/`light`/`dark`); answers like `GET /me` |
| `GET` | `/household` | own household `{ id, name, role, createdAt, members: [{ id, name, initials, avatarUrl, role, joinedAt }], invite }` or null; `invite` (`{ code, expiresAt }`) only for admins |
| `POST` | `/household` | `{ name }`: create (201), the user becomes admin |
| `POST` | `/household/join` | `{ code, switch?, confirmDissolve? }`: join; `switch` leaves the current household first |
| `POST` | `/household/invite` | new invite code (admins) |
| `POST` | `/household/leave` | `{ confirmDissolve? }`: leave; the last member must confirm (the household is deleted) |
| `DELETE` | `/household` | dissolve (admins): household, memberships and its lists are deleted |
| `PATCH` / `DELETE` | `/household/members/{userId}` | `{ role: admin \| member }` / remove a member (admins) |
| `GET` | `/templates` | accessible templates without entries, with `role`, `count`, `preview` (first four product IDs), sorted by name (ETag) |
| `POST` | `/templates` | `{ id, name, householdId, entries: [{ productId, quantity, note }] }`: create (201, again 200 `unchanged`) |
| `GET` | `/templates/{id}` | `{ template }` with `entries`, `role`, `updatedByName` (ETag); `410 template_deleted` (checked first, like lists: also former members of a dissolved household), `403 no_access` |
| `PUT` | `/templates/{id}` | `If-Match: "<revision>"`, `{ name, entries }`: save as a whole; `409 template_changed` with `template`, `428 precondition_required`, `400 invalid_template` / `unknown_product` with `field` |
| `DELETE` | `/templates/{id}` | tombstone (owner, members): `{ status: 'deleted' }` |
| `POST` | `/templates/{id}/move` | `{ to: 'household' }`: personal template into the household (owner) |
| `POST` | `/templates/{id}/copy` | `{ id, to: 'household' \| 'personal' }`: copy (201, again 200 `unchanged`) |
| `POST` | `/lists/{id}/move` | `{ to: 'household' }`: move a personal list into the household (owner) |
| `POST` | `/lists/{id}/copy` | `{ id, to: 'household' \| 'personal' }`: copy (201, again 200 `unchanged`) |
| `POST` | `/lists/{id}/invites` | new one-time link (owner, members): `{ token, invite: { id, createdBy, createdAt, expiresAt } }` (201) |
| `DELETE` | `/lists/{id}/invites/{inviteId}` | withdraw an open link; answers like `GET /lists/{id}/shares` |
| `GET` | `/lists/{id}/shares` | `{ shares: [{ id, name, initials, avatarUrl, invitedBy, acceptedAt }], invites: [{ id, createdBy, createdAt, expiresAt }] }` (owner, members) |
| `DELETE` | `/lists/{id}/shares/{userId}` | revoke (owner, members; answers like `GET`); with the own id a guest leaves (`{ status: 'left' }`) |
| `GET` | `/invites/{token}` | `{ invite: { list: { id, name, shopDate }, invitedBy, expiresAt }, access }`; invalid: `invite_invalid` with `reason` |
| `POST` | `/invites/{token}/accept` | `{ status: 'accepted' \| 'already', list }` |
| `GET` | `/config` | `images`: `maxPixels`, `maxBytes` (project settings), `cropSize` |
| `POST` | `/ops` | operations on lists and entries, and `product.create`, see [Sync](#sync) |
| `GET` | `/lists` | accessible lists without entries, with `role`, `counts` and `preview`, plus `household` (ETag) |
| `GET` | `/lists/{id}?since=N` | one list: all visible entries, or with `since` only entries changed after revision N (ETag) |
| `GET` | `/products` | whole catalog including hidden products, without list items (ETag) |
| `GET` | `/products/search?q=&limit=` | suggestions without hidden products, best first (limit 20, at most 100; `hidden=1` for maintainers) |
| `GET` | `/products/suggestions` | weighted products of the last 90 days for “Produkte hinzufügen”, see [Adding products](#adding-products) |
| `GET` | `/products/{id}` | one product; a list item only with access, else `404` |
| `POST` | `/products` | create with the browser's UUID: `201` `{status: 'created', product}`, again `200` `unchanged` |
| `PATCH` | `/products/{id}` | change the sent fields (creator or maintainer) |
| `POST` | `/products/{id}/hide`, `/unhide` | hide or show again (maintainers only, not list items) |
| `POST` | `/products/{id}/catalog` | take a list item into the catalog (everybody who sees it) |
| `POST` | `/products/{id}/image` | multipart `image` (crop, JPEG 1600 px), optional `original` and `source` (`openfoodfacts`); `400 invalid_image` / `413 too_large` with `field` and `reason` |
| `DELETE` | `/products/{id}/image` | remove the image (fallback icon again) |
| `GET` | `/openfoodfacts/{barcode}?refresh=1` | `{ result }`: hit at Open Food Facts from the server cache, see [Open Food Facts](#open-food-facts); `400 invalid_barcode`, `404 off_not_found`, `429 off_busy`, `503 off_unavailable` |
| `GET` | `/openfoodfacts/{barcode}/image?size=full` | front image of the cached hit (400 px or full size); `404 off_no_image` |
| `POST` | `/products/{id}/openfoodfacts` | `{ barcode, fields }`: take over chosen fields (right to edit) |
| `DELETE` | `/products/{id}/openfoodfacts` | unlink (barcode, ingredients, nutrition) |

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
