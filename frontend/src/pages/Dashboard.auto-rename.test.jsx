import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ComicLibraryProvider } from "@/hooks/use-comic-library.jsx";
import { api } from "@/lib/api";
import Dashboard from "./Dashboard";

const mocks = vi.hoisted(() => ({
  location: null,
  toast: vi.fn(),
  user: { id: 1 },
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), patch: vi.fn() } }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: mocks.user }) }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-library-folders", () => ({
  useLibraryFolders: () => ({
    folders: [], isLoading: false, createFolder: vi.fn(), updateFolder: vi.fn(),
    deleteFolder: vi.fn(), moveComics: vi.fn(),
  }),
}));
vi.mock("@/hooks/use-sharing.jsx", () => ({ useSharing: () => ({ refreshSummary: vi.fn() }) }));
vi.mock("@/hooks/use-library-location", () => ({ useLibraryLocation: () => mocks.location }));
vi.mock("@/hooks/use-library-sorts", () => ({ useLibrarySorts: () => ({ sort: "title-asc", setSort: vi.fn() }) }));
vi.mock("@/hooks/use-library-comic-actions", () => ({ useLibraryComicActions: () => ({}) }));
vi.mock("@/hooks/use-library-folder-actions", () => ({
  useLibraryFolderActions: () => ({ createLibraryFolder: vi.fn(), currentFolder: () => null }),
}));
vi.mock("@/hooks/use-jump-to-comic", () => ({ useJumpToComic: vi.fn() }));

vi.mock("@/components/SearchBar.jsx", () => ({
  SearchBar: ({ onSearch }) => (
    <>
      <button onClick={() => onSearch({ query: "hero", tags: [] })}>Search</button>
      <button onClick={() => onSearch({ query: "", tags: [] })}>Clear search</button>
    </>
  ),
}));
vi.mock("@/components/PendingSharesAlert.jsx", () => ({ PendingSharesAlert: () => null }));
vi.mock("@/components/library/LibraryToolbar", () => ({ LibraryToolbar: () => null }));
vi.mock("@/components/library/LibrarySidebar", () => ({
  LibrarySidebar: () => null,
  DesktopLibrarySidebar: () => null,
}));
vi.mock("@/components/library/LibraryResults", () => ({
  LibraryResults: ({ contents }) => (
    <ul aria-label="Comics">{contents.comics.map((comic) => <li key={comic.id}>{comic.title}</li>)}</ul>
  ),
}));
vi.mock("@/components/library/LibraryDialogs", () => ({
  LibraryDialogs: ({ onShared }) => <button onClick={onShared}>Finish sharing</button>,
}));
vi.mock("@/components/library/LibraryFolderBar", () => ({
  LibraryFolderBar: ({ onAutoRename }) => onAutoRename && <button onClick={onAutoRename}>Auto rename</button>,
}));

const series = (first, name) => [
  { id: first, title: `${name} 1`, canEdit: true, tags: [] },
  { id: first + 1, title: `${name} 10`, canEdit: true, tags: [] },
];
const FOLDER_A = "/api/comics?folder=1";
const FOLDER_B = "/api/comics?folder=2";

const inFolder = (activeFolderId) => {
  mocks.location = {
    isFolderView: true, activeFolderId, activeView: "all", jumpComicId: null,
    ownership: "all", invalidFolder: false, navigateFolder: vi.fn(), navigateView: vi.fn(),
  };
};

/** Answers held per URL, released by the test, so a load can be caught mid-flight. */
function holdResponses() {
  const pending = new Map();
  vi.mocked(api.get).mockImplementation((url) => new Promise((resolve) => {
    pending.set(url, resolve);
  }));
  return async (url, comics) => {
    await waitFor(() => expect(pending.has(url)).toBe(true));
    await act(async () => {
      pending.get(url)({ comics });
      pending.delete(url);
    });
  };
}

const tree = () => <ComicLibraryProvider><Dashboard /></ComicLibraryProvider>;
const autoRename = () => screen.queryByRole("button", { name: "Auto rename" });

describe("Dashboard auto rename", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    inFolder(1);
  });

  it("is offered only once the active folder's own comics have loaded", async () => {
    const respond = holdResponses();
    const user = userEvent.setup();
    const view = render(tree());
    expect(autoRename()).not.toBeInTheDocument();

    await respond(FOLDER_A, series(1, "Alpha"));
    expect(autoRename()).toBeInTheDocument();

    inFolder(2);
    view.rerender(tree());
    // Folder 1's comics are still held, and renaming now would act on them.
    expect(autoRename()).not.toBeInTheDocument();

    await respond(FOLDER_B, series(11, "Beta"));
    await user.click(autoRename());

    expect(screen.getByText("Beta 01")).toBeInTheDocument();
  });

  it("is not offered while clearing a search still shows its results", async () => {
    const respond = holdResponses();
    const user = userEvent.setup();
    render(tree());
    await respond(FOLDER_A, series(1, "Alpha"));

    await user.click(screen.getByRole("button", { name: "Search" }));
    expect(autoRename()).not.toBeInTheDocument();
    await respond("/api/comics", series(21, "hero"));
    expect(screen.getByText("hero 1")).toBeInTheDocument();
    expect(autoRename()).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Clear search" }));
    expect(screen.getByText("hero 1")).toBeInTheDocument();
    expect(autoRename()).not.toBeInTheDocument();

    await respond(FOLDER_A, series(1, "Alpha"));
    expect(autoRename()).toBeInTheDocument();
  });

  it("stays off when returning to the library with another list still held", async () => {
    const respond = holdResponses();
    const view = render(tree());
    await respond(FOLDER_A, series(1, "Alpha"));

    // The provider outlives the page, so the list survives the reader round trip.
    inFolder(2);
    view.rerender(<ComicLibraryProvider />);
    view.rerender(tree());

    expect(autoRename()).not.toBeInTheDocument();
    await respond(FOLDER_B, series(11, "Beta"));
    expect(autoRename()).toBeInTheDocument();
  });

  it("reloads the current folder after sharing rather than the whole library", async () => {
    const respond = holdResponses();
    const user = userEvent.setup();
    render(tree());
    await respond(FOLDER_A, series(1, "Alpha"));

    await user.click(screen.getByRole("button", { name: "Finish sharing" }));
    await respond(FOLDER_A, series(1, "Alpha"));

    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.get).toHaveBeenLastCalledWith(FOLDER_A);
    expect(autoRename()).toBeInTheDocument();
  });
});
