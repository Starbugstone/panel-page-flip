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

## Backend quality gates

```bash
cd backend
composer analyse
composer cs:check
```

PHPStan uses a baseline for pre-existing findings so new code cannot silently increase static-analysis debt. Reduce the baseline opportunistically when touching existing code.
