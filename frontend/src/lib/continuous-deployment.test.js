import { execFileSync, spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdtempSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const read = (relativePath) => readFileSync(resolve(repositoryRoot, relativePath), "utf8");
const temporaryDirectories = [];

function temporaryDirectory() {
  const directory = mkdtempSync(resolve(tmpdir(), "panel-page-flip-deploy-test-"));
  temporaryDirectories.push(directory);
  return directory;
}

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    rmSync(directory, { force: true, recursive: true });
  }
});

describe("the validated deployment workflow", () => {
  const workflow = read(".github/workflows/build-frontend.yml");

  it("deploys only after both validation jobs and reuses their exact frontend artifact", () => {
    expect(workflow).toMatch(/deploy:\n[\s\S]*?needs:\s*\[validate-frontend, validate-backend\]/);
    expect(workflow).toContain("actions/download-artifact@");
    expect(workflow).toMatch(/name:\s*frontend-build/);
    expect(workflow).toContain("deployment-commit.txt");
    expect(workflow).toContain("${{ github.sha }}");
    const freshnessCheck = workflow.indexOf("git ls-remote --exit-code origin");
    expect(freshnessCheck).toBeGreaterThan(-1);
    expect(freshnessCheck).toBeLessThan(workflow.indexOf("Resolve the runner IPv4 address"));
  });

  it("never deploys a pull request and maps only develop and main to isolated environments", () => {
    expect(workflow).toContain("validation-only");
    expect(workflow).toMatch(/refs\/heads\/develop/);
    expect(workflow).toMatch(/refs\/heads\/main/);
    expect(workflow).toMatch(/environment:[\s\S]*?staging[\s\S]*?production/);
    expect(workflow).toMatch(/github\.event_name\s*==\s*'push'/);
    expect(workflow).toMatch(/inputs\.deploy_target\s*==\s*'staging'[\s\S]*?github\.ref\s*==\s*'refs\/heads\/develop'/);
    expect(workflow).toMatch(/inputs\.deploy_target\s*==\s*'production'[\s\S]*?github\.ref\s*==\s*'refs\/heads\/main'/);
  });

  it("serializes branch deployments without cancelling a running push", () => {
    expect(workflow).toMatch(/cancel-in-progress:\s*\$\{\{\s*github\.event_name\s*==\s*'pull_request'\s*\}\}/);
    expect(workflow).toMatch(/group:[^\n]*github\.ref/);
  });

  it("always removes only the temporary runner IP in both directions", () => {
    expect(workflow).toMatch(/if:\s*always\(\)/);
    expect(workflow).toMatch(/o2-firewall\.sh remove[^\n]*in/);
    expect(workflow).toMatch(/o2-firewall\.sh remove[^\n]*out/);
    expect(workflow).not.toContain("remove_all");
  });
});

describe("the O2Switch firewall helper", () => {
  const helper = resolve(repositoryRoot, "scripts/ci/o2-firewall.sh");

  function mockCurl(directory) {
    const executable = resolve(directory, "curl");
    writeFileSync(executable, `#!/usr/bin/env bash
set -euo pipefail
url="\${!#}"
printf '%s\\n' "$url" >> "$MOCK_CURL_LOG"
case "$url" in
  */list) printf '%s\\n' "$MOCK_LIST_RESPONSE" ;;
  */add*) printf '%s\\n' "$MOCK_ADD_RESPONSE" ;;
  */remove*) printf '%s\\n' "$MOCK_REMOVE_RESPONSE" ;;
  *) exit 90 ;;
esac
`);
    chmodSync(executable, 0o755);
  }

  function firewallEnvironment(directory, list) {
    const curlLog = resolve(directory, "curl.log");
    return {
      ...process.env,
      PATH: `${directory}:${process.env.PATH}`,
      CPANEL_SERVER: "server.example.test",
      CPANEL_USERNAME: "account",
      CPANEL_API_TOKEN: "NOTAREALTOKEN123",
      MOCK_CURL_LOG: curlLog,
      MOCK_LIST_RESPONSE: JSON.stringify({ status: 1, data: { list } }),
      MOCK_ADD_RESPONSE: JSON.stringify({ status: 1, data: null }),
      MOCK_REMOVE_RESPONSE: JSON.stringify({ status: 1, data: null }),
    };
  }

  it("records ownership before adding a previously absent IPv4 address", () => {
    const directory = temporaryDirectory();
    mockCurl(directory);
    const marker = resolve(directory, "owned-ip");
    const environment = firewallEnvironment(directory, []);

    execFileSync("bash", [helper, "add", "192.0.2.40", marker], { env: environment });

    expect(readFileSync(marker, "utf8")).toBe("192.0.2.40\n");
    expect(readFileSync(environment.MOCK_CURL_LOG, "utf8")).toMatch(/\/list[\s\S]*\/add\?/);
  });

  it("refuses to claim or remove an address that was already whitelisted", () => {
    const directory = temporaryDirectory();
    mockCurl(directory);
    const marker = resolve(directory, "owned-ip");
    const environment = firewallEnvironment(directory, [
      { address: "192.0.2.40", port: 22, direction: "in" },
    ]);

    const result = spawnSync("bash", [helper, "add", "192.0.2.40", marker], {
      encoding: "utf8",
      env: environment,
    });

    expect(result.status).not.toBe(0);
    expect(existsSync(marker)).toBe(false);
    expect(readFileSync(environment.MOCK_CURL_LOG, "utf8").trim()).toMatch(/\/list$/);
  });

  it("rejects non-IPv4 input before contacting cPanel", () => {
    const directory = temporaryDirectory();
    mockCurl(directory);
    const environment = firewallEnvironment(directory, []);

    const result = spawnSync("bash", [helper, "add", "not-an-ip", resolve(directory, "owned-ip")], {
      encoding: "utf8",
      env: environment,
    });

    expect(result.status).not.toBe(0);
    expect(existsSync(environment.MOCK_CURL_LOG)).toBe(false);
  });
});

describe("the server release transaction", () => {
  const deployment = read("scripts/server/server-deploy.sh");
  const deploymentScript = resolve(repositoryRoot, "scripts/server/server-deploy.sh");
  const manualDeployment = read("scripts/deploy-ssh.sh");
  const serverInstaller = read("scripts/server/server-install.sh");

  it("backs up before fetching or changing the checkout and verifies the resulting SHA", () => {
    const backup = deployment.indexOf("Running pre-deploy backup");
    const fetch = deployment.indexOf("git -C \"$APP_DIR\" fetch");
    const checkout = deployment.indexOf("git -C \"$APP_DIR\" checkout");
    const composer = deployment.indexOf('log "composer install --no-dev"');
    const migrations = deployment.indexOf('log "doctrine:migrations:migrate"');

    expect(backup).toBeGreaterThan(-1);
    expect(fetch).toBeGreaterThan(backup);
    expect(checkout).toBeGreaterThan(fetch);
    expect(composer).toBeGreaterThan(checkout);
    expect(migrations).toBeGreaterThan(composer);
    expect(deployment).toMatch(/git -C "\$APP_DIR" merge-base --is-ancestor/);
    expect(deployment).toContain("DEPLOY_SHA");
    expect(deployment).toMatch(/git -C "\$APP_DIR" rev-parse HEAD[\s\S]*DEPLOY_SHA/);
  });

  it("requires a clean source checkout and an environment identity marker", () => {
    expect(deployment).toMatch(/git -C "\$APP_DIR" status --porcelain/);
    expect(deployment).toContain(".panel-page-flip-environment");
    expect(deployment).toContain("DEPLOY_ENVIRONMENT");
  });

  it("rejects unsafe staging runtime configuration before backup", () => {
    const stagingGuard = deployment.indexOf("Validating staging isolation before backup");
    const backup = deployment.indexOf("Running pre-deploy backup");

    expect(stagingGuard).toBeGreaterThan(-1);
    expect(backup).toBeGreaterThan(stagingGuard);
  });

  it("preserves host access control in the manual fallback and forwards installer identity", () => {
    expect(manualDeployment).toContain("--exclude='backend/public/.htaccess'");
    expect(serverInstaller).toMatch(/DEPLOY_ENVIRONMENT="\$DEPLOY_ENVIRONMENT"[\s\S]*?"\$deploy_script"/);
  });

  it("rejects an APP_URL that disagrees with host configuration before backup", () => {
    const root = temporaryDirectory();
    const application = resolve(root, "application");
    const backupMarker = resolve(root, "backup-ran");
    const backup = resolve(root, "backup");

    mkdirSync(resolve(application, "backend"), { recursive: true });
    execFileSync("git", ["init", "--initial-branch=main"], { cwd: application });
    execFileSync("git", ["config", "user.email", "deploy-test@example.invalid"], { cwd: application });
    execFileSync("git", ["config", "user.name", "Deployment test"], { cwd: application });
    writeFileSync(resolve(application, ".gitignore"), ".panel-page-flip-environment\nbackend/.env.local\n");
    writeFileSync(resolve(application, "backend", "tracked.txt"), "tracked\n");
    execFileSync("git", ["add", "."], { cwd: application });
    execFileSync("git", ["commit", "-m", "application"], { cwd: application });
    writeFileSync(resolve(application, ".panel-page-flip-environment"), "production\n");
    writeFileSync(resolve(application, "backend", ".env.local"), "APP_URL=https://wrong.example.test\n");
    writeFileSync(backup, `#!/usr/bin/env bash\ntouch ${backupMarker}\n`);
    chmodSync(backup, 0o755);

    const result = spawnSync("bash", [deploymentScript], {
      encoding: "utf8",
      env: {
        ...process.env,
        APP_DIR: application,
        APP_URL: "https://expected.example.test",
        BACKUP_COMMAND: backup,
        DEPLOY_ENVIRONMENT: "production",
        SKIP_COMPOSER: "1",
        SKIP_FRONTEND: "1",
      },
    });

    expect(result.status).not.toBe(0);
    expect(existsSync(backupMarker)).toBe(false);
    expect(result.stderr).toContain("APP_URL");
  });

  it("leaves the checkout and uploads untouched when backup fails", () => {
    const root = temporaryDirectory();
    const origin = resolve(root, "origin.git");
    const source = resolve(root, "source");
    const application = resolve(root, "application");
    const artifact = resolve(root, "artifact");
    const failingBackup = resolve(root, "backup-fails");

    execFileSync("git", ["init", "--bare", "--initial-branch=main", origin]);
    execFileSync("git", ["clone", origin, source]);
    execFileSync("git", ["config", "user.email", "deploy-test@example.invalid"], { cwd: source });
    execFileSync("git", ["config", "user.name", "Deployment test"], { cwd: source });
    mkdirSync(resolve(source, "backend", "public"), { recursive: true });
    writeFileSync(resolve(source, ".gitignore"), ".panel-page-flip-environment\nbackend/.env.local\nbackend/public/uploads/\n");
    writeFileSync(resolve(source, "backend", "version.txt"), "before\n");
    execFileSync("git", ["add", "."], { cwd: source });
    execFileSync("git", ["commit", "-m", "before"], { cwd: source });
    execFileSync("git", ["push", "origin", "main"], { cwd: source });
    execFileSync("git", ["clone", origin, application]);
    const previousSha = execFileSync("git", ["rev-parse", "HEAD"], { cwd: application, encoding: "utf8" }).trim();

    writeFileSync(resolve(source, "backend", "version.txt"), "after\n");
    execFileSync("git", ["add", "."], { cwd: source });
    execFileSync("git", ["commit", "-m", "after"], { cwd: source });
    execFileSync("git", ["push", "origin", "main"], { cwd: source });
    const targetSha = execFileSync("git", ["rev-parse", "HEAD"], { cwd: source, encoding: "utf8" }).trim();

    mkdirSync(resolve(application, "backend", "public", "uploads"), { recursive: true });
    writeFileSync(resolve(application, ".panel-page-flip-environment"), "production\n");
    writeFileSync(resolve(application, "backend", ".env.local"), "APP_URL=https://comics.example.test\n");
    writeFileSync(resolve(application, "backend", "public", "uploads", "comic.cbz"), "production data\n");
    mkdirSync(resolve(artifact, "assets"), { recursive: true });
    writeFileSync(resolve(artifact, "index.html"), '<script src="/assets/application.js"></script>\n');
    writeFileSync(resolve(artifact, "assets", "application.js"), "application\n");
    writeFileSync(resolve(artifact, "deployment-commit.txt"), `commit=${targetSha}\napp_url=https://comics.example.test\n`);
    writeFileSync(failingBackup, "#!/usr/bin/env bash\nexit 71\n");
    chmodSync(failingBackup, 0o755);

    const result = spawnSync("bash", [deploymentScript], {
      encoding: "utf8",
      env: {
        ...process.env,
        APP_DIR: application,
        APP_URL: "https://comics.example.test",
        BACKUP_COMMAND: failingBackup,
        DEPLOY_BRANCH: "main",
        DEPLOY_ENVIRONMENT: "production",
        DEPLOY_SHA: targetSha,
        PREBUILT_FRONTEND_DIR: artifact,
      },
    });

    expect(result.status).not.toBe(0);
    expect(execFileSync("git", ["rev-parse", "HEAD"], { cwd: application, encoding: "utf8" }).trim()).toBe(previousSha);
    expect(readFileSync(resolve(application, "backend", "version.txt"), "utf8")).toBe("before\n");
    expect(readFileSync(resolve(application, "backend", "public", "uploads", "comic.cbz"), "utf8")).toBe("production data\n");
  });

  it("rejects a superseded workflow SHA before backup or checkout mutation", () => {
    const root = temporaryDirectory();
    const origin = resolve(root, "origin.git");
    const source = resolve(root, "source");
    const application = resolve(root, "application");
    const backupMarker = resolve(root, "backup-ran");
    const backup = resolve(root, "backup");

    execFileSync("git", ["init", "--bare", "--initial-branch=main", origin]);
    execFileSync("git", ["clone", origin, source]);
    execFileSync("git", ["config", "user.email", "deploy-test@example.invalid"], { cwd: source });
    execFileSync("git", ["config", "user.name", "Deployment test"], { cwd: source });
    mkdirSync(resolve(source, "backend"), { recursive: true });
    writeFileSync(resolve(source, ".gitignore"), ".panel-page-flip-environment\nbackend/.env.local\n");
    writeFileSync(resolve(source, "backend", "version.txt"), "validated\n");
    execFileSync("git", ["add", "."], { cwd: source });
    execFileSync("git", ["commit", "-m", "validated"], { cwd: source });
    execFileSync("git", ["push", "origin", "main"], { cwd: source });
    execFileSync("git", ["clone", origin, application]);
    const supersededSha = execFileSync("git", ["rev-parse", "HEAD"], { cwd: application, encoding: "utf8" }).trim();

    writeFileSync(resolve(source, "backend", "version.txt"), "newest\n");
    execFileSync("git", ["add", "."], { cwd: source });
    execFileSync("git", ["commit", "-m", "newest"], { cwd: source });
    execFileSync("git", ["push", "origin", "main"], { cwd: source });
    writeFileSync(resolve(application, ".panel-page-flip-environment"), "production\n");
    writeFileSync(resolve(application, "backend", ".env.local"), "APP_URL=https://comics.example.test\n");
    writeFileSync(backup, `#!/usr/bin/env bash\ntouch ${backupMarker}\n`);
    chmodSync(backup, 0o755);

    const result = spawnSync("bash", [deploymentScript], {
      encoding: "utf8",
      env: {
        ...process.env,
        APP_DIR: application,
        APP_URL: "https://comics.example.test",
        BACKUP_COMMAND: backup,
        DEPLOY_BRANCH: "main",
        DEPLOY_ENVIRONMENT: "production",
        DEPLOY_SHA: supersededSha,
        SKIP_COMPOSER: "1",
        SKIP_FRONTEND: "1",
      },
    });

    expect(result.status).not.toBe(0);
    expect(existsSync(backupMarker)).toBe(false);
    expect(execFileSync("git", ["rev-parse", "HEAD"], { cwd: application, encoding: "utf8" }).trim()).toBe(supersededSha);
    expect(result.stderr).toContain("superseded");
  });
});

describe("the server backup gate", () => {
  const backupScript = resolve(repositoryRoot, "scripts/server/backup-comics.sh");

  it("backs up the effective production-local database when dotenv layers coexist", () => {
    const root = temporaryDirectory();
    const application = resolve(root, "application");
    const binaries = resolve(root, "bin");
    const backupRoot = resolve(root, "backups");
    const mysqlLog = resolve(root, "mysqldump.log");

    mkdirSync(resolve(application, "backend", "public", "uploads"), { recursive: true });
    mkdirSync(binaries);
    writeFileSync(
      resolve(application, "backend", ".env.local"),
      'DATABASE_URL="mysql://wrong_user:wrong@wrong-db.example:3306/wrong_db"\n',
    );
    writeFileSync(
      resolve(application, "backend", ".env.prod.local"),
      'DATABASE_URL="mysql://right_user:correct%21@right-db.example:3307/right_db?serverVersion=8.0"\n',
    );
    writeFileSync(
      resolve(binaries, "mysqldump"),
      '#!/usr/bin/env bash\nprintf \'password=%s\\n\' "$MYSQL_PWD" > "$MYSQL_LOG"\nprintf \'arg=%s\\n\' "$@" >> "$MYSQL_LOG"\nprintf \'SELECT 1;\\n\'\n',
    );
    writeFileSync(resolve(binaries, "gzip"), "#!/usr/bin/env bash\ncat\n");
    writeFileSync(resolve(binaries, "rsync"), "#!/usr/bin/env bash\nexit 0\n");
    chmodSync(resolve(binaries, "mysqldump"), 0o755);
    chmodSync(resolve(binaries, "gzip"), 0o755);
    chmodSync(resolve(binaries, "rsync"), 0o755);

    execFileSync("bash", [backupScript], {
      env: {
        ...process.env,
        APP_DIR: application,
        BACKUP_ROOT: backupRoot,
        MYSQL_LOG: mysqlLog,
        PATH: `${binaries}:${process.env.PATH}`,
      },
    });

    const invocation = readFileSync(mysqlLog, "utf8");
    expect(invocation).toContain("password=correct!");
    expect(invocation).toContain("arg=right_user");
    expect(invocation).toContain("arg=right-db.example");
    expect(invocation).toContain("arg=3307");
    expect(invocation).toContain("arg=right_db");
    expect(invocation).not.toContain("wrong_db");
  });
});

describe("prebuilt frontend installation", () => {
  const installer = resolve(repositoryRoot, "scripts/server/install-frontend.sh");

  function fixture(environment = "production") {
    const root = temporaryDirectory();
    const source = resolve(root, "source");
    const publicDirectory = resolve(root, "public");
    const state = resolve(root, "state");
    mkdirSync(resolve(source, "assets"), { recursive: true });
    mkdirSync(resolve(source, "tools"), { recursive: true });
    mkdirSync(resolve(publicDirectory, "assets"), { recursive: true });
    mkdirSync(resolve(publicDirectory, "uploads"), { recursive: true });
    writeFileSync(resolve(source, "index.html"), '<meta name="robots" content="index, follow" />\n<div id="root"></div>\n');
    writeFileSync(resolve(source, "robots.txt"), "User-agent: *\nAllow: /\n");
    writeFileSync(resolve(source, "sitemap.xml"), "<urlset />\n");
    writeFileSync(resolve(source, "deployment-commit.txt"), "commit=0123456789abcdef0123456789abcdef01234567\n");
    writeFileSync(resolve(source, "assets", "new.js"), "new\n");
    writeFileSync(resolve(source, "tools", "converter.zip"), "zip\n");
    writeFileSync(resolve(publicDirectory, ".htaccess"), "cPanel Directory Privacy\n");
    writeFileSync(resolve(publicDirectory, "assets", "old.js"), "old\n");
    writeFileSync(resolve(publicDirectory, "uploads", "comic.cbz"), "user data\n");

    return { environment, publicDirectory, root, source, state };
  }

  function install({ environment, publicDirectory, source, state }) {
    execFileSync("bash", [installer], {
      env: {
        ...process.env,
        DEPLOY_ENVIRONMENT: environment,
        DEPLOY_STATE_DIR: state,
        FRONTEND_SOURCE_DIR: source,
        PUBLIC_DIR: publicDirectory,
      },
    });
  }

  it("preserves uploads and keeps the previous hashed assets addressable", () => {
    const deployment = fixture();

    install(deployment);

    expect(readFileSync(resolve(deployment.publicDirectory, "uploads", "comic.cbz"), "utf8")).toBe("user data\n");
    expect(readFileSync(resolve(deployment.publicDirectory, ".htaccess"), "utf8")).toBe("cPanel Directory Privacy\n");
    expect(readFileSync(resolve(deployment.publicDirectory, "assets", "old.js"), "utf8")).toBe("old\n");
    expect(readFileSync(resolve(deployment.publicDirectory, "assets", "new.js"), "utf8")).toBe("new\n");
    expect(readFileSync(resolve(deployment.publicDirectory, "tools", "converter.zip"), "utf8")).toBe("zip\n");
    expect(existsSync(resolve(deployment.publicDirectory, "deployment-commit.txt"))).toBe(false);
  });

  it("fails before changing live files when the prebuilt artifact is incomplete", () => {
    const deployment = fixture();
    rmSync(resolve(deployment.source, "index.html"));

    const result = spawnSync("bash", [installer], {
      encoding: "utf8",
      env: {
        ...process.env,
        DEPLOY_ENVIRONMENT: deployment.environment,
        DEPLOY_STATE_DIR: deployment.state,
        FRONTEND_SOURCE_DIR: deployment.source,
        PUBLIC_DIR: deployment.publicDirectory,
      },
    });

    expect(result.status).not.toBe(0);
    expect(readFileSync(resolve(deployment.publicDirectory, "assets", "old.js"), "utf8")).toBe("old\n");
    expect(existsSync(resolve(deployment.publicDirectory, "assets", "new.js"))).toBe(false);
  });

  it("refuses an artifact that could replace host-owned access control", () => {
    const deployment = fixture();
    writeFileSync(resolve(deployment.source, ".htaccess"), "Require all granted\n");

    const result = spawnSync("bash", [installer], {
      encoding: "utf8",
      env: {
        ...process.env,
        DEPLOY_ENVIRONMENT: deployment.environment,
        DEPLOY_STATE_DIR: deployment.state,
        FRONTEND_SOURCE_DIR: deployment.source,
        PUBLIC_DIR: deployment.publicDirectory,
      },
    });

    expect(result.status).not.toBe(0);
    expect(readFileSync(resolve(deployment.publicDirectory, ".htaccess"), "utf8")).toBe("cPanel Directory Privacy\n");
    expect(existsSync(resolve(deployment.publicDirectory, "assets", "new.js"))).toBe(false);
  });

  it("installs staging-wide anti-indexing metadata", () => {
    const deployment = fixture("staging");

    install(deployment);

    expect(readFileSync(resolve(deployment.publicDirectory, "robots.txt"), "utf8")).toBe("User-agent: *\nDisallow: /\n");
    expect(readFileSync(resolve(deployment.publicDirectory, "index.html"), "utf8")).toContain("noindex, nofollow, noarchive");
  });
});

describe("deployment shell syntax", () => {
  it.each([
    "scripts/ci/o2-firewall.sh",
    "scripts/ci/deploy-o2switch.sh",
    "scripts/ci/http-smoke.sh",
    "scripts/server/install-frontend.sh",
    "scripts/server/server-deploy.sh",
    "scripts/server/server-install.sh",
    "scripts/server/backup-comics.sh",
    "scripts/deploy-ssh.sh",
  ])("%s parses as Bash", (relativePath) => {
    execFileSync("bash", ["-n", resolve(repositoryRoot, relativePath)]);
  });
});
