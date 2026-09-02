import { describe, expect, it } from "vitest";
import {
  DEFAULT_COMIC_FORMATS,
  DEFAULT_CONCURRENT_CHUNKS,
  DEFAULT_PARALLEL_FILES,
  configuredComicFormats,
  configuredConcurrentChunks,
  formatFileSize,
  generateTitleFromFilename,
  isComicFile,
  resolveParallelFiles,
} from "./comic-upload";

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

describe("resolving how many comics upload at once", () => {
  it("takes a configured positive count", () => {
    expect(resolveParallelFiles(5)).toBe(5);
    expect(resolveParallelFiles("3")).toBe(3);
  });

  it("floors a fractional count rather than starting a fraction of a worker", () => {
    expect(resolveParallelFiles(3.7)).toBe(3);
  });

  /**
   * The value crosses the network from /api/config, so a server that answers
   * with nonsense has to cost the queue its speed and nothing else. Zero and
   * negatives matter most: `Array.from({ length: -1 })` throws, and a length of
   * zero starts no workers at all, so "Start all" would silently do nothing.
   */
  it.each([undefined, null, 0, -1, "", "many", NaN, Infinity])(
    "falls back to the default for %p",
    (configured) => {
      expect(resolveParallelFiles(configured)).toBe(DEFAULT_PARALLEL_FILES);
    }
  );
});

describe("shared upload configuration", () => {
  it("uses server upload limits and formats", () => {
    const config = { upload: { maxConcurrentUploads: 7, comicFormats: ["cbz", "pdf"] } };

    expect(configuredConcurrentChunks(config)).toBe(7);
    expect(configuredComicFormats(config)).toEqual(["cbz", "pdf"]);
  });

  it("uses the client fallbacks when the server omits values", () => {
    expect(configuredConcurrentChunks({ upload: {} })).toBe(DEFAULT_CONCURRENT_CHUNKS);
    expect(configuredComicFormats({ upload: {} })).toBe(DEFAULT_COMIC_FORMATS);
  });
});
