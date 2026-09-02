import { act, render, screen } from "@testing-library/react";
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

const renderSearchBar = () => render(
  <TagProvider>
    <SearchBar onSearch={() => {}} />
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
});
