import { describe, expect, it } from "vitest";
import { formatFileSize, generateTitleFromFilename, isCbzFile } from "./comic-upload";

describe("comic upload helpers", () => {
  it.each([
    ["the_dark-knight01.cbz", "The Dark Knight 01"],
    ["AmazingSpiderMan12.CBZ", "Amazing Spider Man 12"],
    ["already readable.cbz", "Already Readable"],
  ])("derives a readable title from %s", (filename, expected) => {
    expect(generateTitleFromFilename(filename)).toBe(expected);
  });

  it("accepts CBZ files case-insensitively", () => {
    expect(isCbzFile({ name: "comic.CBZ" })).toBe(true);
    expect(isCbzFile({ name: "comic.zip" })).toBe(false);
    expect(isCbzFile(null)).toBe(false);
  });

  it.each([
    [100, "100 B"],
    [1536, "1.5 KB"],
    [1572864, "1.5 MB"],
  ])("formats %d bytes", (bytes, expected) => {
    expect(formatFileSize(bytes)).toBe(expected);
  });
});
