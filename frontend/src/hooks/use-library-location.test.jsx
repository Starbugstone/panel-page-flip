import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, useLocation } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import { useLibraryLocation } from "./use-library-location";

const folders = [{ id: 7, name: "Reprints", parentId: null }];

function Harness({ foldersLoading = false, tree = folders, onNavigate }) {
  const location = useLibraryLocation({ folders: tree, foldersLoading, onNavigate });
  const url = useLocation();

  return (
    <div>
      <span data-testid="search">{url.search || "(none)"}</span>
      <span data-testid="folder">{String(location.activeFolderId)}</span>
      <span data-testid="view">{location.activeView}</span>
      <button type="button" onClick={() => location.navigateFolder(7)}>open folder</button>
      <button type="button" onClick={() => location.navigateView("shared")}>show shared</button>
      <button type="button" onClick={() => location.navigateView("all")}>show all</button>
    </div>
  );
}

const renderAt = (path, props = {}) => render(
  <MemoryRouter initialEntries={[path]}><Harness {...props} /></MemoryRouter>
);

describe("useLibraryLocation", () => {
  it("reads the folder the URL names", () => {
    renderAt("/?folder=7");

    expect(screen.getByTestId("folder")).toHaveTextContent("7");
  });

  /**
   * A folder id can outlive the folder — bookmarked, then deleted — and asking
   * the API about one is a guaranteed 404. The fallback runs on a timer rather
   * than during render, because navigating is a state change.
   */
  it("falls back to the root when the folder no longer exists", async () => {
    renderAt("/?folder=99");

    await waitFor(() => expect(screen.getByTestId("search")).toHaveTextContent("folder=root"));
  });

  it("falls back to the root when the folder id is not a number", async () => {
    renderAt("/?folder=..%2Fetc");

    await waitFor(() => expect(screen.getByTestId("search")).toHaveTextContent("folder=root"));
  });

  it("leaves a folder alone while the tree that would vouch for it is loading", async () => {
    renderAt("/?folder=99", { foldersLoading: true, tree: [] });

    await new Promise((resolve) => { setTimeout(resolve, 10); });
    expect(screen.getByTestId("search")).toHaveTextContent("folder=99");
  });

  /**
   * A folder and a view are alternative ways of choosing comics. Merging them
   * into one query string would leave a URL that has to pick one anyway.
   */
  it("replaces the query string rather than merging into it", async () => {
    renderAt("/?view=shared");

    await userEvent.click(screen.getByRole("button", { name: "open folder" }));
    expect(screen.getByTestId("search")).toHaveTextContent("?folder=7");
    expect(screen.getByTestId("search")).not.toHaveTextContent("view=shared");
  });

  it("leaves the default view out of the URL entirely", async () => {
    renderAt("/?view=shared");

    await userEvent.click(screen.getByRole("button", { name: "show all" }));
    expect(screen.getByTestId("search")).toHaveTextContent("(none)");
  });

  it("tells the caller to close the sidebar it navigated from", async () => {
    const onNavigate = vi.fn();
    renderAt("/", { onNavigate });

    await userEvent.click(screen.getByRole("button", { name: "show shared" }));
    expect(onNavigate).toHaveBeenCalled();
  });
});
