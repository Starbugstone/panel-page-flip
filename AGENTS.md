# Panel Page Flip — working rules

## Tests are not optional

**Every code change ships with tests in the same PR.** This is a hard rule, not
a preference.

- New behaviour gets tests that fail without it. Write the test, watch it fail,
  then make it pass — a test that never failed has proved nothing.
- Bug fixes get a regression test reproducing the bug. Verify it fails against
  the unfixed code before committing.
- Changed behaviour gets its existing tests updated to describe the new
  behaviour, not deleted.
- Untestable code is a design problem. Extract the logic into something that can
  be tested directly rather than leaving it uncovered.

The only work that ships without tests is documentation and comments.

For a normal code change, run the tests that cover what you touched. Run the
full list below only before a requested push — all of it, not a subset — unless
the change could break a gate you are not already exercising.

```bash
docker compose exec -T php composer validate --strict
docker compose exec -T php composer audit --locked --no-dev
docker compose exec -T php php bin/console lint:container --env=test
docker compose exec -T php php bin/console lint:twig templates   # all Twig; currently mail
docker compose exec -T php php bin/console doctrine:schema:validate --env=test
docker compose exec -T php composer analyse         # PHPStan
docker compose exec -T php composer cs:check        # PHP-CS-Fixer
docker compose exec -T php php bin/phpunit          # backend
npm run audit:production --prefix frontend
npm run lint --prefix frontend                      # --max-warnings=0
npm test --prefix frontend                          # host; see below
npm run check:routes --prefix frontend              # committed artefacts
npm run check:csp --prefix frontend                 # host only; CSP across nginx targets
npm run check:tools --prefix frontend               # host only; conversion-tool zips
APP_URL=https://comics.starbugstone.com npm run build --prefix frontend
APP_URL=https://comics.starbugstone.com npm run check:seo --prefix frontend
```

CI gates on all of these. `lint` fails on a single warning, and the `check:`
scripts guard generated files that are committed rather than rebuilt on the way
to production — see `docs/development-tooling.md`. `check:seo` reads `APP_URL`
and inspects a build, so the two must use the same value; CI uses
`https://comics.starbugstone.com`.

`check:tools`, `check:csp`, and `frontend/src/lib/conversion-tools.test.js` only
work from the host. `frontend_dev` mounts `./frontend` and only
`scripts/generate-nginx-routes.mjs` out of `scripts/`, because that directory
can hold deploy credentials. `check:tools` needs `scripts/comic-conversion/`
and `check:csp` needs `scripts/generate-csp.mjs`, so inside the container they
report a missing source file. That failure is the mount, not the repository.

Every `docker compose` command above assumes this checkout owns its own stack —
see "One checkout, one stack" below. Running them against another checkout's
containers produces results about that checkout's code.

On a requested push, report failures honestly, with the output. A gate you did
not run is a gate that failed.

## One checkout, one stack

**Run `scripts/dev-env.sh` before the first `docker compose` command in a new
clone or worktree.** It writes `.env` — untracked — with a Compose project name,
a port block and the UID/GID this checkout's containers run as.

`.env` used to be tracked, pinning every checkout to
`COMPOSE_PROJECT_NAME=cbz_reader` and ports 8080/8081/3001/1025/8025. Compose
keys containers by project name, so the main repo and every worktree resolved to
one set of containers, and a container keeps the bind mounts it was created
with. Whichever checkout started the stack first owned it; the rest ran
`docker compose exec -T php php bin/phpunit` against *that* checkout's source
while reading their own diff. The failures look like flaky tests, phantom
regressions, or a fix that "doesn't take" — never like the mount they are.

If a result does not match the code in front of you, check what you are actually
testing before believing it:

```bash
docker compose ps --format '{{.Name}}\t{{.Service}}'
docker inspect "$(docker compose ps -q php)" --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}'
```

The source path must be the checkout you are working in.

**Destroy the stack before deleting a worktree.** `git worktree remove` knows
nothing about Docker and leaves containers running, ports held and volumes
orphaned:

```bash
scripts/dev-down.sh      # this checkout: containers, network, volumes
scripts/dev-gc.sh        # list stacks whose checkout is already gone
scripts/dev-gc.sh --prune
```

Containers run as your host UID/GID, so anything they write into the checkout —
`vendor/`, `var/`, `public/uploads/`, `.phpunit.cache/` — belongs to you. If you
meet a root-owned file left over from before this change, `scripts/fix-ownership.sh`
repairs a checkout without needing sudo. Do not `chown` inside a container to
work around a permission error: that is what created the problem.

## Code should read without commentary

Prefer code that explains itself: precise names, small functions, early returns,
types that make illegal states unrepresentable.

Comment **why**, never **what**. A comment restating the line above it is noise;
a comment recording a constraint, a hazard, or the reason an obvious approach was
rejected is worth its space. If a block needs a comment to be understood at all,
try naming it as a function first.

Match the surrounding file's density and idiom rather than importing a different
house style.

## Untrusted input

Comic sources arrive through an upload form. Anything read out of one — archive
entries, PDF objects, XML, filenames — is attacker-controlled.

- Bound it before allocating: sizes, entry counts, nesting depth, expansion
  ratios.
- Never let a value out of a file reach a filesystem path or a subprocess
  argument without passing through a whitelist or an enum.
- XML parsing must disable external entities and DTDs.
- Failing to read a source is normal. Degrade to a working page, never to a
  stack trace.

## Conventions

- Backend: PHP 8.2 / Symfony 6.4 / Doctrine. New migrations are
  `VersionYYYYMMDDHHMMSS`, MySQL-only, and guard with `abortIf` on the platform.
  Older migrations may omit the guard; do not backfill them unless you are
  already changing that file.
- Frontend: React 19 / Vite / Tailwind / Radix. Node >= 22.12.
- Per-feature documentation lives in `docs/`; `DEV_README.md` indexes every page.
  A behaviour change updates its page in the same PR.
- Branch from `main`; never commit to it directly. `develop` is the integration
  branch — work wanting manual testing on a real deployment lands there first
  and reaches `main` as one merge.
  Always keep the documentation up to date with the latest modifications

## Always commit

GitHub is the source of truth: several agents may be working at once, and
uncommitted work is invisible to them. When a task is finished, commit and push and create PR if necessary
the files that task changed. Do not wait for a separate "please commit" — this
section is that request.

Never commit secrets (`.env`, credentials, deploy keys). That exception is absolute.
