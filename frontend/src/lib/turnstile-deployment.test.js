import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const repositoryFile = (path) => readFileSync(resolve(import.meta.dirname, "../../..", path), "utf8");

describe("Turnstile deployment artefacts", () => {
  it("documents every compiled release input and bakes all three backend values", () => {
    const example = repositoryFile("scripts/.env.deploy.example");
    const build = repositoryFile("scripts/build-release.sh");

    for (const name of ["PROD_TURNSTILE_ENABLED", "PROD_TURNSTILE_SITE_KEY", "PROD_TURNSTILE_SECRET_KEY"]) {
      expect(example).toContain(`${name}=`);
    }
    for (const name of ["TURNSTILE_ENABLED", "TURNSTILE_SITE_KEY", "TURNSTILE_SECRET_KEY"]) {
      expect(build).toContain(`write_dotenv ${name}`);
    }
    expect(build).toContain("are required when PROD_TURNSTILE_ENABLED is true");
  });

  it("keeps the private secret out of every frontend source file", () => {
    const provider = repositoryFile("frontend/src/components/config/PublicConfigProvider.jsx");
    const report = repositoryFile("frontend/src/pages/ReportContent.jsx");

    expect(provider).not.toContain("TURNSTILE_SECRET_KEY");
    expect(report).not.toContain("TURNSTILE_SECRET_KEY");
  });
});
