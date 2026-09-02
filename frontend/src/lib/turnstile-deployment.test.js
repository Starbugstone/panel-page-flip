import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const repositoryFile = (path) => readFileSync(resolve(import.meta.dirname, "../../..", path), "utf8");

describe("Turnstile deployment artefacts", () => {
  it("uses the canonical backend names for every compiled release input", () => {
    const example = repositoryFile("scripts/.env.deploy.example");
    const build = repositoryFile("scripts/build-release.sh");

    for (const name of ["TURNSTILE_ENABLED", "TURNSTILE_SITE_KEY", "TURNSTILE_SECRET_KEY"]) {
      expect(example).toContain(`${name}=`);
      expect(build).toContain(`write_dotenv ${name}`);
    }
    expect(example).not.toContain("PROD_TURNSTILE_");
    expect(build).not.toContain("PROD_TURNSTILE_");
    expect(build).toContain("are required when TURNSTILE_ENABLED is true");
  });

  it("keeps the private secret out of every frontend source file", () => {
    const provider = repositoryFile("frontend/src/components/config/PublicConfigProvider.jsx");
    const report = repositoryFile("frontend/src/pages/ReportContent.jsx");

    expect(provider).not.toContain("TURNSTILE_SECRET_KEY");
    expect(report).not.toContain("TURNSTILE_SECRET_KEY");
  });
});
