# QUIQQER Piklis

Template for web apps that run as an installable PWA in the browser and use
QUIQQER as backend: a Vue 3 + Ionic app as standalone start page of its own
QUIQQER instance, talking to a `quiqqer/rest` API with the QUIQQER session.

Derived from Filmoteka. Whether this structure fits a project, and why it is
built this way: Obsidian note “Grundgerüst PWA QUIQQER Projekt”.

## What is included

- Login with QUIQQER users (core login, own form), session check, 401 handling
- Home and profile (theme light/dark/auto, language de/en/auto, sign out)
- Navigation: sidebar on desktop, floating bar on mobile
- PWA: manifest, service worker (precache, start page stale-while-revalidate),
  update on next start, icons generated from one SVG
- REST API `/api/piklis/v1` (`GET /me`, `PATCH /me/settings`)
- App texts in `frontend/i18n/locales/` (de, en; more languages in a few
  steps), backend texts in `locale/*.xml`
- Example console command, cron, permission, user attributes
- PHPStan level 8, PHPCS, PHPUnit
- Layout guide: what to change for a header, other navigation or another
  look ([architecture](docs/architecture.md#layout))

## New project

1. GitHub: “Use this template”, clone.
2. Rename placeholders, then the script deletes itself:

   ```bash
   ./init.sh <vendor> <package> <app-id> "<Title>"
   ```

3. Colours in `frontend/styles/tokens.css`, `standalone.html` and
   `vite.config.js` (`THEME_COLOR`); icon in `build/icon.svg`, then
   `build/make-icons.sh`.
4. Build and start a test instance (see below), commit.

## Quick start

```bash
npm install
npm run build
```

Test instance: [quiqqer-docker-dev](https://github.com/MichaelDanielczok/quiqqer-docker-dev)
(Docker, QUIQQER with this repository mounted as package). After its
`install-package.sh` the app runs on <http://localhost:8080>. Frontend with
hot reload: `npm run dev` (<http://localhost:5173>, proxies the API to the
test instance).

Run `npm run build` before every commit; the build in `bin/app/` is committed.

## Documentation

- [Architecture](docs/architecture.md): structure, PWA, REST, login, texts
- [Development](docs/development.md): Docker, Vite, tools, deployment
