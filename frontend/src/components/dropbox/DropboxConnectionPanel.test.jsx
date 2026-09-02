import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { DropboxConnectionPanel } from "./DropboxConnectionPanel";
import { DropboxFileList } from "./DropboxFileList";

const connectedDropbox = {
  isConfigured: true,
  isConnected: true,
  lastSync: null,
  syncing: false,
  refreshingFiles: false,
  disconnecting: false,
  syncStatus: null,
  files: [],
  importingPaths: new Set(),
  importAll: vi.fn(),
  refreshFiles: vi.fn(),
  disconnect: vi.fn(),
  importFile: vi.fn(),
};

describe("DropboxConnectionPanel", () => {
  it("stacks connection actions on narrow screens", () => {
    render(<DropboxConnectionPanel dropbox={connectedDropbox} />);

    const actions = screen.getByRole("button", { name: "Import new comics" }).parentElement;
    expect(actions).toHaveClass("w-full", "flex-col", "sm:w-auto", "sm:flex-row");
    expect(actions?.parentElement).toHaveClass("flex-col", "sm:flex-row");
  });
});

describe("DropboxFileList", () => {
  it("wraps untrusted file names and moves the import action below them on narrow screens", () => {
    const file = {
      name: "a-very-long-unbroken-comic-file-name-that-must-not-widen-the-page.cbz",
      path: "/Manga/a-very-long-unbroken-comic-file-name-that-must-not-widen-the-page.cbz",
      size: "10 MB",
      modified: "2026-09-01T00:00:00+00:00",
      tags: ["Manga"],
      synced: false,
    };

    render(<DropboxFileList files={[file]} importingPaths={new Set()} onImport={vi.fn()} />);

    const fileName = screen.getByText(file.name);
    const row = fileName.closest("article");
    expect(fileName).toHaveClass("break-all");
    expect(row).toHaveClass("flex-col", "sm:flex-row");
    expect(screen.getByRole("button", { name: "Import" })).toHaveClass("w-full", "sm:w-auto");
  });
});
