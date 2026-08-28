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

Run before every push — all of it, not a subset, but only before a requested push. never during a normal code update unless necessary:

```bash
docker compose exec -T php php bin/phpunit          # backend
docker compose exec -T php composer analyse         # PHPStan
docker compose exec -T php composer cs:check        # PHP-CS-Fixer
npm test --prefix frontend                          # frontend
npm run lint --prefix frontend                      # --max-warnings=0
npm run build --prefix frontend
npm run check:routes --prefix frontend              # committed artefacts
npm run check:tools --prefix frontend               # host only, see below
npm run check:seo --prefix frontend                 # after build, same APP_URL
```

CI gates on all of these. `lint` fails on a single warning, and the `check:`
scripts guard generated files that are committed rather than rebuilt on the way
to production — see `docs/development-tooling.md`.

`check:tools` and `src/lib/conversion-tools.test.js` only work from the host.
`frontend_dev` deliberately does not mount `scripts/`, which can hold deploy
credentials, so inside the container they report a missing source file. That
failure is the mount, not the repository.

Report failures honestly, with the output. A suite you did not run is a suite
that failed.

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

- Backend: PHP 8.2 / Symfony 6.4 / Doctrine. Migrations are
  `VersionYYYYMMDDHHMMSS`, MySQL-only, and guard with `abortIf` on the platform.
- Frontend: React 19 / Vite / Tailwind / Radix. Node >= 22.12.
- Per-feature documentation lives in `docs/`; `DEV_README.md` indexes every page.
  A behaviour change updates its page in the same PR.
- Branch from `main`; never commit to it directly. `develop` is the integration
  branch — work wanting manual testing on a real deployment lands there first
  and reaches `main` as one merge.
