# Panel Page Flip — agent guide

## Working approach

Complete the requested task, verify the result, and keep changes focused.
Make reasonable implementation decisions without asking for routine approval.
Ask when missing information materially affects scope, correctness, or an
irreversible action.

Use git worktrees for isolated analysis, review, and implementation. Match
file edits, commits, pushes, and PRs to the requested outcome and any explicit
limits from the user. Worktree setup is part of the workflow for these tasks.

Read applicable AGENTS.md instructions before editing. Refresh them when
changing branches or worktrees, when instructions change, or when they are
no longer available in context. Continue existing work on follow-up requests.

## Project context

- Backend: `backend/` — PHP 8.2, Symfony 6.4, Doctrine, MySQL.
- Frontend: `frontend/` — React 19, Vite, Tailwind, Radix; Node >= 22.12.
- Use npm and the committed `frontend/package-lock.json`.
- `DEV_README.md` indexes feature documentation and development workflows.
- `docs/development-tooling.md` explains checks and generated artefacts.
- `docs/local-docker-environment.md` covers checkout setup and troubleshooting.
- `.github/workflows/build-frontend.yml` defines the application CI gates.

Read documentation relevant to the task rather than loading every guide.

## Implementation

Follow existing architecture and nearby conventions. Prefer clear names,
small cohesive functions, and existing abstractions. Explain non-obvious
constraints in comments; avoid narrating the code.

Update affected feature documentation when behaviour or operational steps
change. Add new documentation pages to `DEV_README.md`.

New migrations use `VersionYYYYMMDDHHMMSS` and guard for MySQL with `abortIf`.
Do not modify unrelated historical migrations.

## Security

Comic uploads and everything extracted from them are untrusted.

- Enforce size, entry-count, nesting, and expansion limits before allocating.
- Validate extracted values against an allowlist or enum before using them
  in filesystem paths or subprocess arguments.
- Disable XML external entities and DTDs.
- Handle malformed sources gracefully without exposing stack traces.
- Preserve existing authentication, authorization, and ownership checks.

Never commit secrets, local environment files, credentials, or deploy keys.

## Development environment

Each checkout must own its Docker Compose stack.

Run `scripts/dev-env.sh` before the first Compose command in a new checkout
or worktree. Confirm containers mount the current checkout before trusting
test results, especially when results disagree with the source.

Run frontend tests, `check:csp`, and `check:tools` on the host; the
`frontend_dev` container lacks required repository mounts.

Use `scripts/fix-ownership.sh` for legacy ownership problems.
Run `scripts/dev-down.sh` in the disposable worktree before removing it;
this deletes that stack's volumes as well as its containers.

## Verification

New or changed behaviour needs meaningful automated coverage.
Bug fixes need regression coverage; demonstrate that the test detects the
original bug when feasible, and explain any limitation.

Existing tests may be sufficient for behaviour-preserving changes.
Do not add tests that merely restate the implementation. For visual changes,
inspect the rendered result as well as running applicable automated checks.
Documentation-only changes need content and link checks, not application tests.

Run relevant tests and lint, analysis, or build checks locally. Expand
verification when shared code, dependencies, configuration, security, or
deployment changes could affect other areas.

Use the tooling guide and CI workflow for exact commands and prerequisites.
Run the matching `check:*` command when changing generated artefacts.
Build and run `check:seo` with the same `APP_URL`; CI uses
`https://comics.starbugstone.com`.

All required CI checks must pass before merge. Do not weaken tests,
thresholds, or checks to make a task pass. Avoid repeating successful checks
unless subsequent changes or new evidence justify it.

Report checks as passed, failed, or not run, with reasons and relevant
failure output. Incomplete verification must remain explicit.

## Git and delivery

For a new task, fetch origin and inspect the working tree. Create an isolated
worktree with a task-specific branch from `origin/develop`; implementation
PRs target `develop`. Continue that worktree and branch for follow-up work
on the same task.

Urgent production hotfixes start from `origin/main` and target `main`;
bring the fix into `develop` through a separate PR. Releases promote
`develop` to `main` through one deliberate release merge.

Treat local `main` and `develop` as protected tracking branches.
Do not commit or rewrite history on them. If either has unexpected local
commits, investigate and preserve the work before repairing its tracking
state. Do not merge the branches merely to align their histories.

Preserve unrelated changes. Use separate worktrees for concurrent tasks
and avoid changing another task's checkout or branch.

Before committing, verify the branch and review the staged diff.
Stage task-owned paths explicitly. Exclude caches, logs, coverage, and build
output unless documented as committed artefacts.

When implementation is complete, commit, push the task branch, and create
or update its PR unless the user requested otherwise. Fetch before pushing
and inspect upstream changes before reconciling them; do not force-push.

If verification is blocked, preserve the work in a draft PR and explain
the blocker. Do not present it as ready to merge.

Merging, releasing, and deploying require authorization for those actions.

Finish with a concise account of the result, verification, remaining
limitations, and the commit or PR.
