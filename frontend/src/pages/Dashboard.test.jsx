import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import Dashboard from "./Dashboard";

vi.mock("@/hooks/use-comic-library.jsx", () => ({
  useComicLibrary: () => ({
    comics: [], isLoading: false, isRefreshing: false, error: null,
    loadLibrary: vi.fn(), updateComicProgress: vi.fn(), removeComicsFromLibrary: vi.fn(),
  }),
}));
vi.mock("@/hooks/use-library-folders", () => ({
  useLibraryFolders: () => ({
    folders: [], isLoading: false, createFolder: vi.fn(), updateFolder: vi.fn(),
    deleteFolder: vi.fn(), moveComics: vi.fn(),
  }),
}));
vi.mock("@/hooks/use-sharing.jsx", () => ({ useSharing: () => ({ refreshSummary: vi.fn() }) }));
vi.mock("@/hooks/use-library-location", () => ({
  useLibraryLocation: () => ({
    isFolderView: false, activeFolderId: null, activeView: "all", jumpComicId: null,
    ownership: "all", invalidFolder: false, navigateFolder: vi.fn(), navigateView: vi.fn(),
  }),
}));
vi.mock("@/hooks/use-library-sorts", () => ({ useLibrarySorts: () => ({ sort: "title-asc", setSort: vi.fn() }) }));
vi.mock("@/hooks/use-library-search", () => ({
  useLibrarySearch: () => ({
    isSearching: false, isSearchActive: false, search: vi.fn(), loadComics: vi.fn(), refreshCurrent: vi.fn(),
  }),
}));
vi.mock("@/hooks/use-library-comic-actions", () => ({ useLibraryComicActions: () => ({}) }));
vi.mock("@/hooks/use-library-folder-actions", () => ({
  useLibraryFolderActions: () => ({ createLibraryFolder: vi.fn(), currentFolder: () => null }),
}));
vi.mock("@/hooks/use-folder-comic-title-renamer", () => ({
  useFolderComicTitleRenamer: () => ({ previewComics: [], session: null, startPreview: vi.fn(), accept: vi.fn(), undo: vi.fn() }),
}));
vi.mock("@/hooks/use-library-contents", () => ({
  useLibraryContents: () => ({ visibleComics: [], childFolders: [], folderNames: new Map() }),
}));
vi.mock("@/hooks/use-jump-to-comic", () => ({ useJumpToComic: vi.fn() }));

vi.mock("@/components/SearchBar.jsx", () => ({ SearchBar: () => <div>Search</div> }));
vi.mock("@/components/PendingSharesAlert.jsx", () => ({ PendingSharesAlert: () => null }));
vi.mock("@/components/library/LibraryToolbar", () => ({
  LibraryToolbar: ({ onOpenSidebar }) => <button onClick={onOpenSidebar}>Open library navigation</button>,
}));
vi.mock("@/components/library/LibrarySidebar", () => ({
  LibrarySidebar: () => <nav>Folder tree</nav>,
  DesktopLibrarySidebar: ({ children }) => <aside>{children}</aside>,
}));
vi.mock("@/components/library/LibraryResults", () => ({
  LibraryResults: ({ hasContent }) => <div>{hasContent ? "Library content" : "Empty library"}</div>,
}));
vi.mock("@/components/library/LibraryDialogs", () => ({ LibraryDialogs: () => null }));
vi.mock("@/components/library/LibraryFolderBar", () => ({ LibraryFolderBar: () => null }));
vi.mock("@/components/library/ComicTitleRenameBar", () => ({ ComicTitleRenameBar: () => null }));

describe("Dashboard", () => {
  it("composes the empty library and opens its mobile navigation sheet", async () => {
    const user = userEvent.setup();
    render(<Dashboard />);

    expect(screen.getByText("Empty library")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Open library navigation" }));

    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
  });
});
