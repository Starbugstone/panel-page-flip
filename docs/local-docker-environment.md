# Local Docker environment

The stack runs under Docker Compose: `php` (PHP-FPM), `nginx` (serves the built
frontend and proxies the API), `database` (MySQL 8), `adminer`, `mailpit`, and
`frontend_dev` (Vite with hot reload).

## Before the first command in a checkout

```bash
scripts/dev-env.sh
docker compose up -d --build
```

`dev-env.sh` writes `.env`. It is idempotent: it refreshes the checkout-derived
project, port, UID/GID and `APP_URL` values, preserves the other values you have
changed, and fills in keys added to `.env.example` after a pull.

`.env` is **not tracked**. It carries three things that must differ between
checkouts on the same machine:

| Key | Purpose |
| --- | --- |
| `COMPOSE_PROJECT_NAME` | Which set of containers this checkout owns |
| `NGINX_PORT`, `ADMINER_PORT`, `FRONTEND_DEV_PORT`, `MAILPIT_*_PORT` | Published ports |
| `HOST_UID`, `HOST_GID` | The user the containers run as |

`.env.example` is the tracked template `dev-env.sh` renders from. Add new keys
there, not to `.env`.

The primary checkout keeps the historical name `cbz_reader` and ports
8080/8081/3001/1025/8025, so existing bookmarks and documentation stay true. A
linked worktree gets `cbz_reader_<dirname>_<hash>` and a port block derived from
its path, probed for availability before being written.

## Why the project name is per checkout

Compose keys containers by project name. `.env` used to be committed, so every
`git worktree` inherited `COMPOSE_PROJECT_NAME=cbz_reader` and resolved to the
same containers as the main repo. A container keeps the bind mounts it was
created with, so whichever checkout ran `docker compose up` first owned the
stack, and every other checkout's `docker compose exec -T php php bin/phpunit`
ran against that checkout's source tree.

That failure mode is quiet. The tests run, they pass or fail on real code, and
nothing in the output mentions the mount. It reads as flakiness, as a phantom
regression, or as a fix that refuses to take.

Confirm what you are testing whenever a result and the code disagree:

```bash
docker inspect "$(docker compose ps -q php)" --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}'
```

The path must be the checkout you are sitting in.

Container names are global to the Docker daemon, so no service sets
`container_name` — a literal name makes two checkouts fight over one container
instead of getting one each. Compose derives `<project>-<service>-1` instead.

## Why containers run as your UID

`php` and `frontend_dev` bind-mount the source tree read-write and create files
in it: `vendor/`, `var/`, `public/uploads/`, `.phpunit.cache/`,
`package-lock.json`. Both run as `${HOST_UID}:${HOST_GID}`, and the images build
a user with those IDs (`HOST_UID`/`HOST_GID` build args), so that output belongs
to you.

Before this, the PHP container's CLI ran as root and PHP-FPM ran as `www-data`
(uid 33). Neither exists on the host, so the files they produced could not be
edited, overwritten or deleted from the host without sudo — and the next
`composer install`, `cache:clear` or `git clean` failed on them. The errors
surface far from the cause and look like application faults.

Two consequences worth knowing:

- **`nginx` mounts the backend read-only.** It serves uploads and public assets
  and has no reason to write into the checkout.
- **PHP-FPM logs two `'user' directive is ignored when FPM is not running as
  root` notices at boot.** Expected. The pool user in
  `docker/php/www-pool.conf` applies when the master starts as root; when the
  container already runs as that user it is redundant.

Never `chown` inside a container to clear a permission error — that is what
produced the root-owned tree in the first place. If you meet one left over from
before this change:

```bash
scripts/fix-ownership.sh            # this checkout
scripts/fix-ownership.sh PATH ...   # specific directories
```

It borrows root from a throwaway container, so it needs no sudo on the host.

## Tearing a stack down

A stack outlives the worktree it belongs to. `git worktree remove` leaves the
containers running, their published ports held — which pushes the next worktree
further up the port range — and their volumes orphaned.

```bash
scripts/dev-down.sh              # containers, network and volumes
scripts/dev-down.sh --keep-data  # keep db_data and php_cache
scripts/dev-gc.sh                # list stacks whose checkout no longer exists
scripts/dev-gc.sh --prune        # remove them
```

`dev-gc.sh` identifies orphans from the `com.docker.compose.project.working_dir`
label rather than from `.env`, so it still works after the checkout is gone.

## WSL

The checkout must live on the Linux filesystem (`/home/...`), not under
`/mnt/c`. A `drvfs` mount does not carry Unix ownership faithfully, and the
UID-matching above has nothing to attach to. A Windows-side checkout of this
repo opened through Docker Desktop is a separate machine as far as Compose is
concerned; if one has ever run this stack, check `docker compose ls -a` for a
project whose config path is a `D:\...` string and remove it with `dev-gc.sh`.
