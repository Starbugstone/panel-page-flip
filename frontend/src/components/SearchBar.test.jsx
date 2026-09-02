import { act, render } from "@testing-library/react";
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
});
