import { describe, expect, it } from "vitest";
import { formatFileSize, generateTitleFromFilename, isComicFile } from "./comic-upload";

describe("comic upload helpers", () => {
  it.each([
    ["the_dark-knight01.cbz", "The Dark Knight 01"],
    ["AmazingSpiderMan12.CBZ", "Amazing Spider Man 12"],
    ["already readable.cbz", "Already Readable"],
    ["European-album.cbr", "European Album"],
    ["scanned_book.PDF", "Scanned Book"],
  ])("derives a readable title from %s", (filename, expected) => {
    expect(generateTitleFromFilename(filename)).toBe(expected);
  });

  // The queue refuses to upload a row with no title, so a name that derives to
  // nothing has to fall back to something rather than to "".
  it.each([".cbz", "___.cbz", "- -.cbr"])("keeps %s as its own title rather than deriving nothing", (filename) => {
    expect(generateTitleFromFilename(filename)).toBe(filename);
  });

  it.each(["comic.CBZ", "comic.cbr", "comic.CB7", "comic.cbt", "comic.PDF"])("accepts %s", (name) => {
    expect(isComicFile({ name })).toBe(true);
  });

  it("rejects unsupported files", () => {
    expect(isComicFile({ name: "comic.zip" })).toBe(false);
    expect(isComicFile(null)).toBe(false);
  });

  it.each([
    [100, "100 B"],
    [1536, "1.5 KB"],
    [1572864, "1.5 MB"],
  ])("formats %d bytes", (bytes, expected) => {
    expect(formatFileSize(bytes)).toBe(expected);
  });
});
