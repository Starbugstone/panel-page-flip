import { spawnSync } from "node:child_process";

const audit = spawnSync("npm", ["audit", "--omit=dev", "--json"], {
  encoding: "utf8",
  shell: process.platform === "win32",
});

if (audit.error) {
  console.error(`Unable to run npm audit: ${audit.error.message}`);
  process.exit(1);
}

let report;
try {
  report = JSON.parse(audit.stdout);
} catch {
  console.error(audit.stderr || audit.stdout || "npm audit returned invalid JSON");
  process.exit(1);
}

const advisories = new Map();
for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
  for (const cause of vulnerability.via ?? []) {
    if (typeof cause === "object" && cause.url) {
      advisories.set(cause.url, cause);
    }
  }
}

if (advisories.size > 0) {
  for (const advisory of advisories.values()) {
    console.error(`${advisory.severity}: ${advisory.title} (${advisory.url})`);
  }
  process.exit(1);
}

console.log("No production dependency advisories found.");
