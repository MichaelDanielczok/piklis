# Development

## Development instance

Piklis has its own QUIQQER instance (Homebrew Apache/PHP, MySQL):
`~/code/piklis`, <https://piklis.michael.pcsg.eu>, project `piklis` (German
only). This repository is the package under
`packages/michaeldanielczok/piklis`, `dev-main` installed from source.
The repository is a `git` source in `etc/source.list.ini.php` (QUIQQER
rebuilds `var/composer/composer.json` from it and forces
`preferred-install: dist`; a `vcs` source would try the GitHub zip, which
gives 404 for the private repo). The clone needs the remotes `origin` and
`composer`. The instance also requires `quiqqer/rest`,
`quiqqer/rest-dummy-authentication` and `quiqqer/cache`.

- **Commit and push before every Composer update**: the update resets the
  clone to the state on GitHub (`discard-changes: true`).
- Changes to PHP and `bin/app/` are live at once.
- After changing `*.xml` files: `./console package --setup=michaeldanielczok/piklis`
  in the instance root, then `./console clear-cache`.
- After changing `standalone.html`: paste it into “Standalone Content” of
  the start page (site 1, type `quiqqer/sitetypes:types/standalone`) again.
- Design package “Leinen”: `design/piklis-designpaket/` in the instance root.
- `quiqqer/cache` (Einstellungen › QUIQQER › Cache,
  `etc/plugins/quiqqer/cache.ini.php`): `webp=1`; `cache`, `htmlcache`,
  `jscache` and `lazyloading` are `0`, so the start page, the REST API and
  the service worker go through untouched. `cwebp` must be in the `PATH`
  of PHP-FPM (`env[PATH]` in `php-fpm.d/www.conf`, Homebrew:
  `/opt/homebrew/bin`).
- Project settings of `piklis` (Medien): `media_maxUploadSize` = 2048
  (pixels, at least 1600 = crop size) and `media_maxUploadFileSize` =
  15728640 (15 MB). PHP-FPM 8.4 (`/opt/homebrew/etc/php/8.4/php.ini`):
  `upload_max_filesize` and `post_max_size` 25M.
- [quiqqer-docker-dev](https://github.com/MichaelDanielczok/quiqqer-docker-dev)
  still works for a disposable instance (`install-package.sh`).

## Mock backend

For testing without login (Claude Code's built-in browser, screenshots) the
instance has a mock backend in `.claude/` (not part of this repository):

- `.claude/mock/router.php` for `php -S localhost:8180`: the real instance,
  but the session user comes from the cookie `pk_mock_user`
  (`test` = Michael, default · `test2` = his wife · `test3` = his brother ·
  `nobody` = signed out). Switch with
  <http://localhost:8180/__mock/user/test2> or
  `document.cookie = 'pk_mock_user=test2; path=/'`. Local only.
- `.claude/mock/create-users.php` creates the three local test users
  without password (cannot sign in normally); `test` and `test2` get
  `piklis.products.manage`.
- `.claude/launch.json`: `piklis-mock-php` (port 8180), `piklis-vite-mock`
  (Vite on <http://localhost:5180> against the mock) and
  `piklis-vite-instance` (Vite on 5181 against the real instance, with
  login).
- The pattern page of the base components: <http://localhost:5180/#/components>
  (dev server only).
- The router sets `_url` for `/media/cache/…` like the `.htaccess`, so
  missing WebP sizes are created in the mock as well.
- The catalog is global: products the test users create in the mock are
  real rows in `pk_products`, their images real media in “Produktbilder”.
  Delete them after testing (`created_by` of `test`, `test2`, `test3`;
  remove the image first or delete the media in the backend).

## Frontend

```bash
npm install
npm run dev
```

- Vite proxies `/api/piklis/`, `/ajax.php` and `/media/cache/` to the
  instance (`APP_BACKEND`, default `http://localhost:8080`; here
  `http://localhost:8180` for the mock or `https://piklis.michael.pcsg.eu`) and rewrites
  `Origin`, otherwise the core rejects the login.
- No service worker in the dev server; test the PWA with a build.
- `npm run build` before every commit; `bin/app/` is committed. Entry files
  have fixed names (`app.js`, `app.css`), chunks and assets are hashed.
- `frontend/index.html` mirrors `standalone.html` for Vite.
- Icons: edit `build/icon.svg`, `build/icon-maskable.svg`, `build/favicon.svg`,
  run `build/make-icons.sh` (ImageMagick).

## PHP tools

PHPUnit, PHPStan, PHPCS and PHPCBF are PHARs installed with
[phive](https://phar.io) (`.phive/phars.xml`) into `tools/`, which is not
committed.

```bash
phive install --copy
# in the instance
php tools/phpunit -c phpunit.dist.xml
php -d memory_limit=1G tools/phpstan analyse
php tools/phpcs
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
- Unit tests (`tests/unit/`) cover logic without database; the sync rules
  run against an in-memory store (`tests/unit/Sync/Fixtures`), product
  images against an in-memory media store (`tests/unit/Images/Fixtures`).
- Integration tests (`tests/integration/`) use the real QUIQQER media of
  the standard project (folders, names, cache addresses compared with the
  core, deleting) and remove everything they create; skipped without a
  project.

## JS tests

```bash
npm test
```

- `node --test` from Node itself, no test library. `tests/js/alias-hooks.js`
  resolves `@/…` to `frontend/…` like Vite.
- Sort keys and quantities exist in PHP and JS; both test suites read the
  same cases from `tests/fixtures/*.json`. Change a case only together with
  both implementations.

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
