import { afterEach, describe, expect, it, vi } from "vitest";

import { copyText } from "./clipboard";

const withClipboard = (writeText) => {
  Object.defineProperty(navigator, "clipboard", {
    value: writeText ? { writeText } : undefined,
    configurable: true,
    writable: true,
  });
};

afterEach(() => {
  withClipboard(undefined);
  delete document.execCommand;
  vi.restoreAllMocks();
});

describe("copying text", () => {
  it("uses the async clipboard when the origin has one", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    withClipboard(writeText);

    expect(await copyText("C-ABCD-EFGH-JKMN")).toBe(true);
    expect(writeText).toHaveBeenCalledWith("C-ABCD-EFGH-JKMN");
  });

  /**
   * The regression this exists for. A self-hosted install on plain HTTP has no
   * `navigator.clipboard` at all, and every copy button on it used to fail.
   */
  it("falls back to a selection copy where there is no clipboard API", async () => {
    document.execCommand = vi.fn().mockReturnValue(true);

    expect(await copyText("C-ABCD-EFGH-JKMN")).toBe(true);
    expect(document.execCommand).toHaveBeenCalledWith("copy");
  });

  it("falls back when the clipboard API refuses", async () => {
    withClipboard(vi.fn().mockRejectedValue(new Error("denied")));
    document.execCommand = vi.fn().mockReturnValue(true);

    expect(await copyText("C-ABCD-EFGH-JKMN")).toBe(true);
    expect(document.execCommand).toHaveBeenCalledWith("copy");
  });

  it("reports failure rather than claiming a copy that did not happen", async () => {
    document.execCommand = vi.fn().mockReturnValue(false);

    expect(await copyText("C-ABCD-EFGH-JKMN")).toBe(false);
  });

  it("leaves nothing behind in the document", async () => {
    document.execCommand = vi.fn().mockReturnValue(true);

    await copyText("C-ABCD-EFGH-JKMN");

    expect(document.querySelector("textarea")).toBeNull();
  });

  it("gives focus back to whatever had it", async () => {
    document.execCommand = vi.fn().mockReturnValue(true);
    const button = document.createElement("button");
    document.body.appendChild(button);
    button.focus();

    await copyText("C-ABCD-EFGH-JKMN");

    expect(document.activeElement).toBe(button);
    button.remove();
  });

  it("says no to nothing at all", async () => {
    expect(await copyText("")).toBe(false);
    expect(await copyText(undefined)).toBe(false);
  });
});
