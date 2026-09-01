import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

/**
 * The files that decide what production actually serves. Nothing executes them
 * until they are on the host, where a mistake is a security header that quietly
 * stopped being sent. Checked from here because the PHP suite runs in a
 * container that mounts only `backend/`.
 */
const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const read = (relativePath) => readFileSync(resolve(repositoryRoot, relativePath), "utf8");

describe("the Apache front controller rules", () => {
  const htaccess = read("scripts/deploy/htaccess.dist");
  const rewriteTargets = [...htaccess.matchAll(/^\s*RewriteRule\s+\S+\s+(\S+)/gm)].map((match) => match[1]);

  /**
   * Serving the built index.html straight off disk answers /login and /library
   * without Symfony ever running — and Symfony is what attaches the CSP nonce
   * to the script tags in that file. A static shell arrives with no policy at
   * all, on exactly the routes AdSense is allowed to load on.
   */
  it("sends client-side routes to Symfony rather than a static SPA shell", () => {
    expect(rewriteTargets).toContain("%{ENV:BASE}/index.php");
    expect(rewriteTargets).not.toContain("%{ENV:BASE}/index.html");
  });

  it("does not let a request for the SPA shell bypass the front controller", () => {
    expect(htaccess).toMatch(/RewriteRule\s+\^index\\\.html\$\s+%\{ENV:BASE\}\/\s+\[R=301,L\]/);
  });

  /**
   * `Header always set` replaces rather than merges, so a policy here would
   * overwrite the per-response nonce header and block every script on the page.
   */
  it("leaves the Content-Security-Policy to Symfony", () => {
    expect(htaccess).not.toMatch(/Header\s+(always\s+)?set\s+Content-Security-Policy/i);
  });
});

describe("the Nginx request logs", () => {
  it("never records OAuth codes or other query values", () => {
    const source = read("docker/nginx_frontend/nginx.conf");
    const format = source.match(/log_format ppf_without_query[\s\S]*?;/)?.[0] ?? "";

    expect(format).toContain("$ppf_request_path");
    expect(format).not.toMatch(/\$request(?:_uri)?\b/);
    expect(source).toMatch(/access_log\s+\/var\/log\/nginx\/project_access\.log\s+ppf_without_query;/);
  });
});

describe("the frontend development service", () => {
  const compose = read("docker-compose.yml");
  const frontendDockerfile = read("docker/frontend_dev/Dockerfile");

  /**
   * The service builds from a Dockerfile rather than using the image directly,
   * but only to create a user matching the host's UID — the base image is still
   * the supported Node version, and there is still exactly one development
   * stack. A second one (`docker/node`, `Dockerfile.dev`, `nginx.dev.conf`)
   * drifts from what production builds and is what this guards against.
   */
  it("builds its single development stack on the supported Node image", () => {
    expect(frontendDockerfile).toContain("FROM node:${NODE_VERSION}-alpine");
    expect(compose).toContain("NODE_VERSION=${NODE_VERSION:-22}");
    expect(compose).not.toMatch(/docker\/node|Dockerfile\.dev|nginx\.dev\.conf/);
  });

  /**
   * `/app` is a bind mount of the source tree, so `npm install` rewrites the
   * tracked lockfile whenever the container's npm differs from the one that
   * wrote it — a diff that appears on every stack start with nothing tying it
   * to Docker, on the file CI installs from.
   */
  it("installs with npm ci so it cannot rewrite the tracked lockfile", () => {
    expect(compose).toMatch(/command:.*npm ci\b/);
    expect(compose).not.toMatch(/command:.*npm install\b/);
  });
});

/**
 * Two defects here produced test failures that pointed nowhere near their
 * cause, so each one keeps a test. See docs/local-docker-environment.md.
 */
describe("the local Docker environment", () => {
  const compose = read("docker-compose.yml");
  const gitignore = read(".gitignore");

  // Bounded at the next service key. Slicing to the end of the file instead
  // would let one service's setting satisfy an assertion about another's.
  const serviceBlock = (name) => {
    const start = compose.indexOf(`\n  ${name}:\n`);
    expect(start, `no ${name} service`).toBeGreaterThan(-1);
    const next = compose.slice(start + 1).search(/\n {2}[a-z_]+:\n/);
    return next === -1 ? compose.slice(start) : compose.slice(start, start + 1 + next);
  };

  /**
   * Compose keys containers by project name. While `.env` was tracked, every
   * git worktree inherited COMPOSE_PROJECT_NAME=cbz_reader and resolved to the
   * same containers as the main repo — and a container keeps the bind mounts it
   * was created with, so a checkout ran its tests against whichever checkout
   * had started the stack first.
   */
  it("keeps .env untracked so each checkout gets its own Compose project", () => {
    expect(gitignore).toMatch(/^\/\.env$/m);
    expect(() => read(".env.example")).not.toThrow();
  });

  /**
   * Container names are global to the Docker daemon, so a literal name makes
   * two checkouts fight over one container instead of getting one each.
   */
  it("never pins a container_name", () => {
    expect(compose).not.toMatch(/container_name:/);
  });

  /**
   * Both services create files in the bind-mounted source tree. As root — or as
   * www-data, which does not exist on the host either — that output could not
   * be edited or deleted without sudo, and broke the next composer install or
   * cache clear.
   */
  it.each(["php", "frontend_dev"])("runs %s as the host user", (service) => {
    expect(serviceBlock(service)).toMatch(/user: "\$\{HOST_UID:-1000\}:\$\{HOST_GID:-1000\}"/);
  });

  /**
   * Docker passes APP_URL into the container and Dotenv::bootEnv() does not
   * override a real environment variable, so .env.test's value loses to
   * whatever the developer's stack publishes — which is per checkout now. The
   * suite asserts canonical URLs against localhost:8080, so without this pin
   * every worktree fails nine tests for reasons that have nothing to do with
   * the code. It must be <env>: variables_order is EGPCS in docker/php, so the
   * container's value reaches $_ENV, which Symfony reads before $_SERVER.
   */
  it("pins APP_URL for the backend suite so it does not depend on the port", () => {
    expect(read("backend/phpunit.xml.dist")).toMatch(
      /<env name="APP_URL" value="http:\/\/localhost:8080" force="true" \/>/,
    );
  });

  /**
   * Nginx serves uploads and public assets and has no reason to write into the
   * checkout; anything it did write would arrive owned by the nginx user.
   */
  it("mounts the backend read-only into nginx", () => {
    expect(serviceBlock("nginx")).toContain("./backend:/var/www/html:ro");
  });
});

describe("deployments and the host's runtime configuration", () => {
  const ftp = read("scripts/deploy-ftp.sh");
  const ssh = read("scripts/deploy-ssh.sh");
  const build = read("scripts/build-release.sh");
  const deployExample = read("scripts/.env.deploy.example");
  const serverInstaller = read("scripts/server/server-install.sh");

  /**
   * `backend/.env.local` is the supported production source of truth and is
   * never in the release. An FTP mirror or an rsync `--delete-after` that did
   * not skip it would take the installation's configuration with it.
   */
  it.each([
    ["scripts/deploy-ftp.sh", ftp, '--exclude-glob ".env.local"'],
    ["scripts/deploy-ssh.sh", ssh, "--exclude='backend/.env.local'"],
  ])("%s never overwrites or deletes the server's .env.local", (_path, source, exclusion) => {
    expect(source).toContain(exclusion);
  });

  /**
   * The mirror image of the rule above. In compiled mode `.env.local.php` is
   * the configuration the build just produced, so excluding it unconditionally
   * ships a release that cannot read a database password.
   */
  it.each([
    ["scripts/deploy-ftp.sh", ftp],
    ["scripts/deploy-ssh.sh", ssh],
  ])("%s only protects .env.local.php when the release does not carry one", (_path, source) => {
    const serverLocalOnly = source.match(/!= "compiled" \]; then\n([\s\S]*?)\n\s*fi/)?.[1] ?? "";
    const exclusions = (text) => text.split("\n").filter((line) => /exclude.*\.env\.local\.php/.test(line));

    expect(exclusions(serverLocalOnly)).toHaveLength(1);
    expect(exclusions(source)).toHaveLength(1);
  });

  it("carries optional social sign-in credentials into compiled releases", () => {
    for (const name of ["OAUTH_GOOGLE_CLIENT_ID", "OAUTH_GOOGLE_CLIENT_SECRET"]) {
      expect(deployExample).toContain(`PROD_${name}=`);
      expect(build).toContain(`write_dotenv ${name} "\${PROD_${name}:-}"`);
    }
  });

  it("schedules every required retention command in the server installer", () => {
    for (const command of [
      "app:cleanup-personal-data",
      "app:cleanup-expired-shares",
      "app:cleanup-content-reports",
      "app:cleanup-logs",
    ]) {
      expect(serverInstaller).toContain(command);
    }
    expect(serverInstaller).toContain("without these four");
  });
});
