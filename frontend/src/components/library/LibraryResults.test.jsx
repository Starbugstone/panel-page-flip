import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import { LibraryResults } from "./LibraryResults";

const emptyState = {
  isSearchActive: false,
  isFolderView: true,
  activeView: "folders",
  uploadUrl: "/upload?folder=root",
  onClearSearch: vi.fn(),
};

const contents = (overrides = {}) => ({
  viewMode: "grid",
  comics: [],
  childFolders: [],
  folders: [],
  folderNames: new Map(),
  showLocation: false,
  onOpenFolder: vi.fn(),
  onEditComic: vi.fn(),
  comicActions: {},
  onShareComics: vi.fn(),
  ...overrides,
});

const renderResult = (props) => render(
  <MemoryRouter>
    <LibraryResults
      showSkeleton={false}
      error={null}
      hasContent={false}
      onRetry={vi.fn()}
      emptyState={emptyState}
      contents={contents()}
      {...props}
    />
  </MemoryRouter>
);

describe("LibraryResults", () => {
  it("keeps loading distinct from an empty library", () => {
    const { container } = renderResult({ showSkeleton: true });

    expect(container.querySelectorAll(".animate-pulse")).toHaveLength(6);
    expect(screen.queryByText("This folder is empty.")).not.toBeInTheDocument();
  });

  it("offers a retry when loading failed", async () => {
    const retry = vi.fn();
    renderResult({ error: "Library unavailable", onRetry: retry });

    await userEvent.click(screen.getByRole("button", { name: "Try Again" }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it("offers an upload for an empty folder", () => {
    renderResult({});

    expect(screen.getByText("This folder is empty.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Upload a comic" })).toHaveAttribute("href", "/upload?folder=root");
  });

  it("renders child folders and opens the selected one", async () => {
    const onOpenFolder = vi.fn();
    renderResult({
      hasContent: true,
      contents: contents({
        childFolders: [{ id: 7, name: "Manga" }],
        onOpenFolder,
      }),
    });

    await userEvent.click(screen.getByRole("button", { name: /Manga/ }));
    expect(onOpenFolder).toHaveBeenCalledWith(7);
  });
});
