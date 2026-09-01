# Development tooling

## Frontend

npm is the supported package manager. `frontend/package-lock.json` is the single lockfile used locally, in Docker and in CI.

```bash
cd frontend
npm ci
npm run lint
npm run test
npm run build
```

Do not commit a Bun lockfile unless package-manager policy deliberately changes.

`npm run lint` runs with `--max-warnings=0`. A warning fails the build, so there is no such thing as a lint warning that can be left for later.

### Checks over committed artefacts

Four things in this repository are generated, committed, and never rebuilt on the way to production. Each has a check that fails when the committed copy stops matching its source:

```bash
npm run check:routes   # nginx SPA route manifest vs frontend/index.html
npm run check:tools    # conversion-tool zips and their published checksums
npm run check:seo      # sitemap, robots.txt, canonicals, crawlable landing copy
npm run check:csp      # strict Content-Security-Policy in nginx
```

`check:seo` reads `APP_URL` and inspects a build, so run `npm run build` with the same `APP_URL` first. It also requires the built `index.html` to contain the public landing copy from `src/lib/landing-copy.js`, because production serves that file to crawlers that never run the React tree. `check:tools` is what stops an edit to a script under `scripts/comic-conversion/` shipping a download that no longer matches the checksum displayed beside it.

### Content-Security-Policy

`backend/config/csp.json` contains the shared policy inputs. Symfony reads it to
build Apache responses with a cryptographic per-response nonce.
`scripts/generate-csp.mjs` emits the equivalent `$request_id` nonce policy into
the production nginx header include:

| File | Form |
| --- | --- |
| `docker/nginx_frontend/security-headers.conf` | nginx `add_header`, production |

Run `node scripts/generate-csp.mjs` after editing the manifest, and
`npm run check:csp --prefix frontend` to verify — CI runs the check.

Local development is served directly by the Node 22 Vite container declared in
`docker-compose.yml`. There is no second Node/nginx development image to keep in
sync with that service.

Apache `.htaccess` must not add CSP: a second static policy would intersect with
the dynamic Symfony header and reject its nonce. The nonce and
`strict-dynamic` replace the unsupported static Google script-origin list;
non-script sources remain explicit in the manifest.

### Crawlable landing page

`/` is indexable. The words on that page live in `frontend/src/lib/landing-copy.js`. `Landing.jsx` renders them after JavaScript starts; `frontend/index.html` already contains them inside `#root` so a client that never executes the bundle still sees the library, sharing, and page-delivery copy. A unit test fails when the HTML first render drops a phrase, and `check:seo` fails when the production build does.

### `check:tools` and `check:csp` do not run inside `frontend_dev`

Run them from the host:

```bash
npm run check:tools --prefix frontend
npm run check:csp --prefix frontend
```

The `frontend_dev` container mounts `./frontend` and exactly one file out of `scripts/` — the route generator. Mounting the whole directory would put `scripts/.env.deploy`, which can hold production FTP/SSH credentials, inside a container that runs `npm install` on every start. `check:tools` needs `scripts/comic-conversion/`, and `check:csp` needs `scripts/generate-csp.mjs`, so in the container they stop with a missing source file. `frontend/src/lib/conversion-tools.test.js` fails with `check:tools` because it shells out to the same check.

So `docker compose exec frontend_dev npm test` reports a failure that CI does not. It is the mount, not the repository. Run the frontend suite from the host when you need a clean result.

### Dependency audit

```bash
npm run audit:production
```

Production dependencies only — a development-only advisory does not block a release. The same script runs weekly from `.github/workflows/security-audit.yml`.

## Backend quality gates

```bash
cd backend
composer validate --strict
composer audit --locked --no-dev
php bin/console lint:container --env=test
php bin/console lint:twig templates   # all Twig; currently mail
php bin/console doctrine:schema:validate --env=test
composer analyse      # PHPStan
composer cs:check     # PHP-CS-Fixer, dry run
php bin/phpunit
```

PHPStan uses a baseline for pre-existing findings so new code cannot silently increase static-analysis debt. Reduce the baseline opportunistically when touching existing code.

The baseline is a record of accepted debt, not a list of harmless noise: entries have hidden real bugs here before. When an entry sits on code you are changing, read what it is actually claiming before re-baselining it.

## What CI enforces

`.github/workflows/build-frontend.yml` ("Validate Application") runs every command on this page — both halves — on pull requests into `main`, `develop`, `feature/**`, `docs/**`, `fix/**` and `ci/**`, and on pushes to `main` and `develop`. It validates and does not deploy.

The pre-push list in `AGENTS.md` is the same set. This page explains the gates; CI is what enforces them.
