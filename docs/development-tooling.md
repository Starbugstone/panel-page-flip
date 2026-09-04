# Development tooling

## Frontend

npm is the supported package manager. `frontend/package-lock.json` is the single lockfile used locally, in Docker and in CI.

```bash
cd frontend
npm ci
npm run lint
npm run test
npm run test:coverage
npm run check:dead-code
npm run check:duplication
npm run build
```

Do not commit a Bun lockfile unless package-manager policy deliberately changes.

`npm run lint` runs with `--max-warnings=0`. A warning fails the build, so there is no such thing as a lint warning that can be left for later.
Production functions also have a cyclomatic-complexity ceiling of 15. Split
view derivation, policy, and side effects into named units instead of raising
the ceiling; this keeps the single-responsibility audit as a permanent gate.

`test:coverage` instruments every production JavaScript and JSX source file,
including files a test never imports. The checked-in thresholds ratchet the
current statement, branch, function and line totals, and the follow-up policy
check rejects any coverable production file with zero executed lines. Lowering
a threshold requires an explicitly reviewed policy change. `check:dead-code` uses Knip to reject
unreachable source files, unused dependencies, unlisted imports and duplicate
exports. Test-only access to an internal helper is not treated as a dead
production file.

`check:duplication` scans production PHP, JavaScript, JSX and operational
scripts for repeated blocks of at least 15 lines and 100 tokens. The threshold
is zero: a match must be expressed once or shown to be smaller structural
boilerplate by tightening the detector deliberately, not ignored ad hoc.

### Checks over committed artefacts

Four things in this repository are generated, committed, and never rebuilt on the way to production. Each has a check that fails when the committed copy stops matching its source:

```bash
npm run check:routes   # nginx SPA route manifest vs frontend/index.html
npm run check:tools    # conversion-tool zips and their published checksums
npm run check:seo      # sitemap, robots.txt, canonicals, crawlable landing copy
npm run check:csp      # strict Content-Security-Policy in nginx
```

`check:seo` reads `APP_URL` and inspects a build, so run `npm run build` with the same `APP_URL` first. It also requires the built `index.html` to contain the public landing copy from `src/lib/landing-copy.js`, because production serves that file to crawlers that never run the React tree. `check:tools` is what stops an edit to a script under `scripts/comic-conversion/` shipping a download that no longer matches the checksum displayed beside it.

CI also runs ShellCheck across every Bash deployment and conversion script, then
executes the Unix and Windows conversion suites on their native runners. The
download checksum check proves that the published archives match the source;
the platform suites prove that the source still converts, skips, reports, and
cleans up correctly.

### Content-Security-Policy

`backend/config/csp.json` contains the shared policy inputs. Symfony reads it to
build Apache responses with a cryptographic per-response nonce.
`scripts/generate-csp.mjs` emits both nginx profiles from it:

| File | Form |
| --- | --- |
| `docker/nginx_frontend/base-headers.conf` | the headers that never vary by route |
| `docker/nginx_frontend/security-headers.conf` | base headers + the Google-capable `$request_id` nonce policy |
| `docker/nginx_frontend/security-headers-google-free.conf` | base headers + the strict policy for the legal routes |

The Google-free profile is derived by removing every origin in the manifest's
`googleOrigins` from every directive, so an origin added above cannot survive
here by being forgotten; the generator refuses to run if a Google-shaped source
appears in a directive without being declared there.

Run `node scripts/generate-csp.mjs` after editing the manifest, and
`npm run check:csp --prefix frontend` to verify — CI runs the check.

nginx also substitutes that request id into every initial script tag. Exact
indexable route blocks rewrite canonical metadata with their own `sub_filter`
directives; because nginx then stops inheriting the server-level filters,
`scripts/generate-nginx-routes.mjs` repeats the nonce substitution inside each
such block. The exception is the `googleFree` routes in
`backend/config/frontend-routes.json`, which instead include the strict header
snippet and are deliberately not nonced — a nonce is what would let a trusted
module pull descendants in under `strict-dynamic`, and Google requires the
privacy-policy URL to carry no consent-requiring script. The
deployment-artefact tests execute the generator and require the nonce filter on
every other indexable route, so a direct legal-page request cannot be left at
the non-interactive SEO fallback by CSP.

The router also reloads the document when crossing between the two CSP profiles;
otherwise a direct legal-page visit would keep blocking Google after navigation
to the library, and the reverse direction would retain already-loaded Google code.

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
composer test:coverage
```

`composer test:coverage` runs the complete suite with PCOV and rejects line or
method coverage below the checked-in ratchet, as well as any coverable source
file with zero executed lines. PCOV is installed in the PHP image
but disabled for ordinary CLI and FPM requests; only this command enables it.

PHPStan checks all backend source at level 8 with the Doctrine and Symfony
extensions and no baseline. A finding must be resolved in the code or explained
by a narrowly scoped type assertion; generating a baseline would hide new debt
and is not part of the workflow.

## What CI enforces

`.github/workflows/build-frontend.yml` ("Validate Application") runs every command on this page — both halves — on pull requests into `main`, `develop`, `feature/**`, `docs/**`, `fix/**` and `ci/**`, and on pushes to `main` and `develop`. It validates and does not deploy.

The pre-push list in `AGENTS.md` names the mandatory release checks, while CI
also applies the coverage, dead-code and duplication ratchets described here.
This page explains the gates; CI is what enforces them.
