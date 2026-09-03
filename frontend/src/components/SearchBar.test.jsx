import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { SearchBar } from "./SearchBar";
import { TagProvider } from "@/hooks/use-tags";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

const { authUser, toast } = vi.hoisted(() => ({ authUser: { id: 7 }, toast: vi.fn() }));

vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: authUser }) }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

const settleEffects = async () => {
  await act(async () => {
    await Promise.resolve();
  });
};

const renderSearchBar = (onSearch = vi.fn()) => render(
  <TagProvider>
    <SearchBar onSearch={onSearch} />
  </TagProvider>,
);

describe("SearchBar tag loading", () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it("waits for the backoff interval before retrying a network failure", async () => {
    vi.useFakeTimers();
    vi.spyOn(logger, "error").mockImplementation(() => {});
    vi.spyOn(logger, "log").mockImplementation(() => {});
    const getTags = vi.spyOn(api, "get")
      .mockRejectedValueOnce(new Error("network unavailable"))
      .mockResolvedValue({ tags: [] });

    renderSearchBar();
    await settleEffects();

    expect(getTags).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(999);
    });
    expect(getTags).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1);
    });
    expect(getTags).toHaveBeenCalledTimes(2);
  });

  it("cancels a scheduled retry when it is unmounted", async () => {
    vi.useFakeTimers();
    vi.spyOn(logger, "error").mockImplementation(() => {});
    const getTags = vi.spyOn(api, "get").mockRejectedValue(new Error("network unavailable"));

    const { unmount } = renderSearchBar();
    await settleEffects();
    unmount();

    await act(async () => {
      await vi.runAllTimersAsync();
    });

    expect(getTags).toHaveBeenCalledTimes(1);
  });

  it("keeps the mobile controls and tag picker inside the viewport", async () => {
    const user = userEvent.setup();
    const longTagName = "A very long personal tag name for narrow screens";
    vi.spyOn(api, "get").mockResolvedValue({
      tags: [{ id: 1, name: longTagName, isGlobal: false }],
    });

    renderSearchBar();
    await user.click(await screen.findByRole("button", { name: "Tags" }));

    const searchInput = screen.getByPlaceholderText("Search comics by title, author...");
    expect(searchInput.closest("form")).toHaveClass("grid", "grid-cols-2", "sm:flex");
    expect(searchInput.parentElement).toHaveClass("col-span-2", "min-w-0", "sm:col-span-1");

    const tagButton = screen.getByRole("button", { name: "Tags" });
    expect(tagButton).toHaveClass("w-full", "sm:w-auto");
    expect(tagButton.parentElement).toHaveClass("w-full", "sm:w-auto");
    expect(screen.getByRole("button", { name: "Search" })).toHaveClass("w-full", "sm:w-auto");

    const panel = screen.getByRole("dialog", { name: "Tag filters" });
    expect(panel).toHaveClass(
      "fixed",
      "inset-x-4",
      "bottom-4",
      "max-h-[calc(100dvh-2rem)]",
      "sm:absolute",
    );
    expect(screen.getByText(longTagName)).toHaveClass("truncate");
  });

  it("dismisses the tag picker with Escape and restores focus to its trigger", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "get").mockResolvedValue({ tags: [] });

    renderSearchBar();
    const tagButton = await screen.findByRole("button", { name: "Tags" });
    await user.click(tagButton);
    expect(screen.getByRole("dialog", { name: "Tag filters" })).toBeInTheDocument();

    await user.keyboard("{Escape}");

    expect(screen.queryByRole("dialog", { name: "Tag filters" })).not.toBeInTheDocument();
    expect(tagButton).toHaveFocus();
  });

  it("dismisses the tag picker when focus moves to an outside pointer target", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "get").mockResolvedValue({ tags: [] });

    renderSearchBar();
    await user.click(await screen.findByRole("button", { name: "Tags" }));
    await user.click(screen.getByRole("searchbox", { name: "Search comics" }));

    expect(screen.queryByRole("dialog", { name: "Tag filters" })).not.toBeInTheDocument();
  });

  it("gives every icon-only search and tag-filter control an accessible name", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "get").mockResolvedValue({
      tags: [{ id: 1, name: "Manga", isGlobal: false }],
    });

    renderSearchBar();
    const search = screen.getByRole("searchbox", { name: "Search comics" });
    await user.type(search, "dragon");
    expect(screen.getByRole("button", { name: "Clear search and tag filters" })).toBeInTheDocument();

    await user.click(await screen.findByRole("button", { name: "Tags" }));
    expect(screen.getByRole("textbox", { name: "Search tags" })).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: "Filter by Manga" }));
    expect(screen.getByRole("button", { name: "Remove Manga filter" })).toBeInTheDocument();
  });

  it("retries a failed tag request without submitting the search form", async () => {
    const user = userEvent.setup();
    const onSearch = vi.fn();
    vi.spyOn(logger, "error").mockImplementation(() => {});
    vi.spyOn(api, "get").mockRejectedValue(Object.assign(new Error("tags unavailable"), { status: 404 }));

    renderSearchBar(onSearch);
    const tagButton = screen.getByRole("button", { name: "Tags" });
    await waitFor(() => expect(tagButton).toBeEnabled());
    await user.click(tagButton);
    await user.click(await screen.findByRole("button", { name: "Retry" }));

    expect(onSearch).not.toHaveBeenCalled();
  });
});
