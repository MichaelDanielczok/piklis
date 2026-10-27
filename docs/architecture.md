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
| `database.xml` | own tables, prefix `pk_`, portable types |
| `permissions.xml` | `piklis.products.manage`: product maintainers may edit all products (sent as `permissions.manageProducts` in `GET /me`) |
| `user.xml` | app settings as user attributes (`pk.theme`) |
| `events.xml` | `onRequest`: service worker and manifest |
| `console.xml`, `cron.xml` | example command `pk:hello`, the same code as cron (created inactive) |
| `locale.xml`, `locale/*.xml` | backend texts (see [Texts](#texts)) |
| `standalone.html` | content of the start page |

PHP under `src/MichaelDanielczok/Piklis/`, grouped by area (`Rest/`,
`Users/`, `Pwa/`, `Console/`). Add repositories per table and a formatter
for JSON output when the app grows.

## Frontend

Sources under `frontend/`, built by Vite into `bin/app/`. The build is
committed because the server does not build (no Node there).

| Path | Content |
| --- | --- |
| `app.js`, `App.vue`, `router.js` | entry, shell, routes |
| `api/index.js` | the only module that talks to the server (REST and core Ajax) |
| `composables/` | shared state: session, toast, page transition |
| `components/layout/` | page frame and header, sidebar, mobile navigation, nav items, user menu |
| `components/ui/` | button, sheet, section label, theme switch, avatar, logo, empty state |
| `components/list/` | list row, row group, skeleton row, quantity, add tile |
| `components/products/` | product image (photo or fallback icon), fallback icon |
| `icons/` | fallback icon sprite (`ersatzicons.svg`, embedded once at start) |
| `utils/quantity.js` | quantities with unit (`2 ×`, `2 Packungen`, `1,5 kg`) |
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

Quantities (`utils/quantity.js`): number plus optional unit from a fixed list
(`piece`, `pack`, `bottle`, `cup`, `can`, `jar`, `bag`, `net`, `bunch`,
`roll`, `bar`, `g`, `kg`, `ml`, `l`); decimals only for `kg` and `l`; without
unit “2 ×”. Texts and plurals in `i18n` (`units.*`, `unitNames.*`).

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

## REST API

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/me` | own user: `id`, `name`, `initials` (first and last name), `avatarUrl`, `settings.theme`, `permissions.manageProducts` |
| `PATCH` | `/me/settings` | `theme` (`auto`/`light`/`dark`); answers like `GET /me` |

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
