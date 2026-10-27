# Architecture

## Overview

- QUIQQER package `michaeldanielczok/piklis`, namespace
  `MichaelDanielczok\Piklis`. One QUIQQER instance per app. No core
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
| `permissions.xml` | `pk.manage` (sent as `permissions.manage` in `GET /me`) |
| `user.xml` | app settings as user attributes (`pk.theme`, `pk.language`) |
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
| `components/layout/` | page frame, sidebar, mobile navigation, nav items |
| `views/` | one view per route |
| `styles/tokens.css` | all colours, sizes and spacing as CSS variables |
| `i18n/` | `t()`, texts in `locales/*.js`, `Intl` for numbers, dates and plurals |
| `public/icons/` | PWA icons and favicon (from `build/icon.svg`) |

- Routes (hash): `/login` (`meta.public`), `/`, `/profile`. Everything except
  public routes requires a session; `meta.manage` additionally requires
  `pk.manage`. A `401` from the API returns to login with
  `?redirect=`.
- Language: user setting, else `localStorage` `pk.language`, else
  device language, else `de`. Theme: `auto | light | dark` via `data-theme`
  on `<html>`; `applyTheme()` also sets both `theme-color` meta tags and
  remembers the theme for the login page.
- Logout clears module state: other composables register a reset with
  `onLogout()` from `useSession.js`.
- Sizes: spacing in quarter rem, radii in eighth rem, font sizes in
  sixteenth rem, breakpoints in `em` (`45em` tablet, `64em` desktop with
  sidebar). Components only use the variables from `tokens.css`.
- Hover styles only inside `@media (hover: hover)` (sticky hover on iOS).
- Ionic’s swipe-back gesture is off; a browser back (Safari gesture)
  switches pages without animation (`usePageTransition.js`). Re-enable the
  gesture in a native shell.

## Layout

The starter ships a deliberately plain shell: sidebar on desktop, floating
bar at the bottom on phones, no header. It is a starting point, not a design
decision; every project shapes its own look. All of it lives in a few files:

| File | Decides |
| --- | --- |
| `App.vue` | which navigation exists and at which width (`64em`: sidebar instead of bottom bar) |
| `components/layout/navItems.js` | navigation targets, icons, order |
| `components/layout/SideNav.vue`, `MobileNav.vue` | look of both navigations |
| `components/layout/AppPage.vue` | frame of every view: scrolling, safe areas, content width |
| `styles/tokens.css` | colours, fonts, spacing, radii, sizes |
| `styles/base.css` | global rules, mapping of Ionic variables, toast |

Common changes:

- **Square instead of pill buttons:** `SideNav.vue`, `.side-nav__link`:
  `border-radius: var(--radius-sm)`. The mobile bar likewise
  (`.mobile-nav`, e.g. `var(--radius-lg)`). Never hard-code values; add a
  token if none fits.
- **Header bar** (back button, search, notifications, avatar): create
  `components/layout/AppHeader.vue`.
  - For all views: in `App.vue` inside `.app-shell__main`, above
    `IonRouterOutlet`; make `.app-shell__main` a flex column and let the
    outlet take the remaining height (`position: relative; flex: 1` instead
    of `inset: 0`).
  - Per view (e.g. a title or actions that differ): render it in
    `AppPage.vue` through a slot or prop.
  - Safe area: the header gets `padding-top: env(safe-area-inset-top)`;
    then the glass strip `.app-page__statusbar` in `AppPage.vue` is no
    longer needed for views below it.
  - Phones: keep it small (title, avatar) or hide parts below `64em`, the
    bottom bar already holds the navigation.
  - Search field: state in a composable (e.g. `useSearch.js`), so header
    and views share it.
- **Other navigation:** only a bottom bar → drop `SideNav` in `App.vue` and
  remove the `64em` rule; only a sidebar → drop `MobileNav` and the bottom
  padding (`--padding-bottom`) in `AppPage.vue`. Groups or lists in the
  sidebar (e.g. people, folders): extend `SideNav.vue`; Filmoteka’s sidebar
  with the wishlists of others is a reference.
- **Colours:** `--color-*` in `tokens.css` (dark is the default, light in two
  blocks: system setting and fixed choice), plus the boot loader and
  `theme-color` in `standalone.html` and `THEME_COLOR` in `vite.config.js`.
- **Fonts:** WOFF2 files under `frontend/fonts/`, `@font-face` in
  `styles/fonts.css` (import in `app.js`), then `--font-text` and
  `--font-display`. Local files only, no font services at runtime.
- **Ionic components** (toast, sheets, modals): their look comes from the
  Ionic variables in `base.css`; `mode: 'ios'` in `app.js` keeps it equal on
  all platforms.

## Texts

- App texts in `frontend/i18n/locales/<language>.js` (nested objects,
  `t('profile.title')`). They are part of `app.js`: no request at start,
  available offline.
- `locale.xml` with `locale/*.xml` holds only backend texts (permissions,
  cron, settings); PHP never needs the app texts.
- Placeholders: `{name}`, numbers formatted per language. Plurals as object
  with the categories of `Intl.PluralRules`; `t('<key>', { count })` picks
  the form:

  ```js
  days: { one: '{count} Tag', other: '{count} Tage' }          // de, en
  days: { one: '{count} dzień', few: '{count} dni', many: '{count} dni', other: '{count} dnia' } // pl
  ```

- Main language and fallback: `de` (`FALLBACK_LOCALE`). Missing keys fall
  back to it, unknown keys show the key itself.

Add a language, e.g. Polish:

1. `frontend/i18n/locales/pl.js`, same keys as `de.js`.
2. `frontend/i18n/index.js`: import it, add to `messages` and
   `LOCALE_NAMES` (`pl: 'Polski'`). The profile picks it up automatically.
3. PHP: `Users\Profile::LANGUAGES` gets `'pl'`.
4. Backend texts: `locale/pl.xml` and an entry in `locale.xml`; activate the
   language in the QUIQQER translator and project.
5. Main language Polish instead of German: `FALLBACK_LOCALE`, `lang` in
   `standalone.html` and `frontend/index.html`, `manifest.lang` in
   `vite.config.js`.

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
| `GET` | `/me` | own user: `id`, `name`, `initials`, `avatarUrl`, `settings`, `permissions.manage` |
| `PATCH` | `/me/settings` | `theme` (`auto`/`light`/`dark`), `language` (`auto`/`de`/`en`); answers like `GET /me` |

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
