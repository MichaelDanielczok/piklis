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
`composer`. The instance also requires `quiqqer/rest` and
`quiqqer/rest-dummy-authentication`.

- **Commit and push before every Composer update**: the update resets the
  clone to the state on GitHub (`discard-changes: true`).
- Changes to PHP and `bin/app/` are live at once.
- After changing `*.xml` files: `./console package --setup=michaeldanielczok/piklis`
  in the instance root, then `./console clear-cache`.
- After changing `standalone.html`: paste it into “Standalone Content” of
  the start page (site 1, type `quiqqer/sitetypes:types/standalone`) again.
- Design package “Leinen”: `design/piklis-designpaket/` in the instance root.
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
