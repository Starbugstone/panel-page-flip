import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join, resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { CONVERSION_TOOLS, CONVERSION_TOOLS_VERSION } from "@/lib/conversion-tools.js";

/**
 * The Settings page offers these downloads as plain static links, so what has
 * to hold is that each link points at a file that is actually committed and
 * that the checksum shown next to it is the checksum of that file. Rendering
 * the card would prove neither.
 */
const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const publicDir = join(frontendDir, "public");

describe("conversion tool downloads", () => {
  it("offers a Windows and a Linux bundle", () => {
    expect(CONVERSION_TOOLS.map((tool) => tool.id).sort()).toEqual(["linux", "windows"]);
  });

  it("is versioned", () => {
    expect(CONVERSION_TOOLS_VERSION).toMatch(/^\d+\.\d+\.\d+$/);
  });

  it.each(CONVERSION_TOOLS)("$id points at a committed file under public/", (tool) => {
    expect(tool.href).toBe(`/tools/${tool.fileName}`);
    expect(() => readFileSync(join(publicDir, "tools", tool.fileName))).not.toThrow();
  });

  it.each(CONVERSION_TOOLS)("$id advertises the checksum and size of that file", (tool) => {
    const bytes = readFileSync(join(publicDir, "tools", tool.fileName));

    expect(createHash("sha256").update(bytes).digest("hex")).toBe(tool.sha256);
    expect(bytes.length).toBe(tool.sizeBytes);
  });

  it.each(CONVERSION_TOOLS)("$id really is a zip, so a browser cannot run it", (tool) => {
    const bytes = readFileSync(join(publicDir, "tools", tool.fileName));

    expect(bytes.subarray(0, 2).toString("latin1")).toBe("PK");
  });

  it("is rendered by the settings page", () => {
    const page = readFileSync(join(frontendDir, "src", "pages", "UserSettings.jsx"), "utf8");

    expect(page).toContain("CONVERSION_TOOLS");
    expect(page).toContain("7-Zip");
  });
});
