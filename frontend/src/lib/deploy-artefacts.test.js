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

  it("uses the supported Node image directly as its single development stack", () => {
    expect(compose).toContain("image: node:${NODE_VERSION:-22}-alpine");
    expect(compose).not.toMatch(/docker\/node|Dockerfile\.dev|nginx\.dev\.conf/);
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
