# Development

## Test instance

Use [quiqqer-docker-dev](https://github.com/MichaelDanielczok/quiqqer-docker-dev):
a disposable QUIQQER instance in Docker (PHP 8.5, MariaDB) with this
repository mounted at `/package` and installed as Composer `path` package
(symlink). Its `install-package.sh` also makes the start page a standalone
page with `standalone.html` and publishes the translations; run it again
after changing `standalone.html`, `*.xml` files or `composer.json`.

- Changes to PHP and `bin/app/` are live at once (symlink).
- App: <http://localhost:8080> (or the configured `HTTP_PORT`).
- `localhost` counts as a secure context, so the service worker works
  without HTTPS. A phone cannot reach `localhost`; test on a device through
  a real host with HTTPS.

## Frontend

```bash
npm install
npm run dev
```

- Vite proxies `/api/piklis/`, `/ajax.php` and `/media/cache/` to the
  instance (`APP_BACKEND`, default `http://localhost:8080`) and rewrites
  `Origin`, otherwise the core rejects the login.
- No service worker in the dev server; test the PWA with a build.
- `npm run build` before every commit; `bin/app/` is committed. Entry files
  have fixed names (`app.js`, `app.css`), chunks and assets are hashed.
- `frontend/index.html` mirrors `standalone.html` for Vite.
- Icons: edit `build/icon.svg`, run `build/make-icons.sh` (ImageMagick).

## PHP tools

PHPUnit, PHPStan, PHPCS and PHPCBF are PHARs installed with
[phive](https://phar.io) (`.phive/phars.xml`) into `tools/`, which is not
committed.

```bash
phive install --copy
# in quiqqer-docker-dev, with the env file of this package
docker compose --env-file envs/<package>.env exec -u www-data -w /package quiqqer sh -c '
  php tools/phpunit -c phpunit.dist.xml
  php -d memory_limit=1G tools/phpstan analyse
  php tools/phpcs'
```

- PHPStan level 8 on `src/` without baseline, PHPCS PSR-12 on `src/` and
  `tests/`. Local overrides in `phpstan.neon` or `phpunit.xml` (ignored).
- The bootstraps load the instance (`QUIQQER_BOOTSTRAP` in quiqqer-docker-dev, else
  `../../../../bootstrap.php` when the package lives in a real instance).
- Unit tests (`tests/unit/`) cover logic without database.

## Testing notes

- The built-in browser of the Claude desktop app does not register service
  workers (“An unknown error occurred when fetching the script”); check the
  PWA in Chrome or Safari.

## Real instance and deployment

- Own QUIQQER instance per app; register the repository as `vcs` source and
  install `dev-main` from source. **Commit before every Composer update**
  (`discard-changes: true` discards local changes).
- Require `quiqqer/rest-dummy-authentication` in the instance.
- Create the start page as standalone page and paste `standalone.html`
  into “Standalone Content”.
- HTTPS is required for the service worker and installation.
- nginx instead of Apache: requests for missing files (`/sw.js`,
  `/manifest.webmanifest`) must reach QUIQQER (`onRequest`).
- Run the package setup after updates (tables, events, permissions, crons).
