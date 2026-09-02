import { describe, expect, it } from "vitest";

import { parseUploadFolderId, resolveUploadFolderId } from "./use-upload-folder-destination";

describe("upload folder destination", () => {
  it.each([
    [null, null],
    ["", null],
    ["root", null],
    ["1.5", null],
    ["-2", null],
    ["42", 42],
  ])("parses %p as %p", (value, expected) => {
    expect(parseUploadFolderId(value)).toBe(expected);
  });

  it("keeps a numeric destination while folders load", () => {
    expect(resolveUploadFolderId(42, [], true)).toBe(42);
  });

  it("accepts only a folder returned by the server after loading", () => {
    const folders = [{ id: "41" }, { id: 42 }];

    expect(resolveUploadFolderId(42, folders, false)).toBe(42);
    expect(resolveUploadFolderId(43, folders, false)).toBeNull();
    expect(resolveUploadFolderId(null, folders, false)).toBeNull();
  });
});
