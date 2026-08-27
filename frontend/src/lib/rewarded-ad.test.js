import { describe, expect, it, vi } from "vitest";

import { requestRewardedAd } from "@/lib/rewarded-ad";

vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));

/**
 * Google plays the advertisement; this application only asks for one and
 * reports what came back. The property worth protecting is that `"viewed"` is
 * unforgeable — everything else about the gate is an audit note, and an audit
 * note that says "watched" whatever happened records nothing at all.
 */
describe("asking Google for a rewarded advertisement", () => {
  it("reports a watched advertisement once Google says the reward is earned", async () => {
    const win = {
      adBreak: ({ beforeReward, adViewed }) => {
        beforeReward(() => {});
        adViewed();
      },
    };

    await expect(requestRewardedAd({ win })).resolves.toBe("viewed");
  });

  it("shows the advertisement as soon as one is available", async () => {
    const showAd = vi.fn();
    const win = {
      adBreak: ({ beforeReward, adViewed }) => {
        beforeReward(showAd);
        adViewed();
      },
    };

    await requestRewardedAd({ win });

    // The user already pressed the button; a second confirmation they never
    // asked for is not a policy requirement, it is a way to lose them.
    expect(showAd).toHaveBeenCalledOnce();
  });

  it("reports an advertisement closed early as dismissed, not watched", async () => {
    const win = {
      adBreak: ({ beforeReward, adDismissed }) => {
        beforeReward(() => {});
        adDismissed();
      },
    };

    await expect(requestRewardedAd({ win })).resolves.toBe("dismissed");
  });

  /** No inventory, a frequency cap, or the format not enabled on the account. */
  it("reports no advertisement at all as unavailable", async () => {
    const win = { adBreak: ({ adBreakDone }) => adBreakDone({ breakStatus: "notReady" }) };

    await expect(requestRewardedAd({ win })).resolves.toBe("unavailable");
  });

  /** The ordinary case: an ad blocker ate the site code, so nothing defined it. */
  it("reports unavailable where Google's API was never loaded", async () => {
    await expect(requestRewardedAd({ win: {} })).resolves.toBe("unavailable");
    await expect(requestRewardedAd({ win: null })).resolves.toBe("unavailable");
  });

  it("survives Google's own code throwing", async () => {
    const win = {
      adBreak: () => {
        throw new Error("adsbygoogle is not defined");
      },
    };

    await expect(requestRewardedAd({ win })).resolves.toBe("unavailable");
  });

  it("gives up when Google never answers at all", async () => {
    vi.useFakeTimers();
    try {
      const win = { adBreak: () => {} };
      const asked = requestRewardedAd({ win, timeoutMs: 8000 });

      await vi.advanceTimersByTimeAsync(8000);

      await expect(asked).resolves.toBe("unavailable");
    } finally {
      vi.useRealTimers();
    }
  });

  /**
   * The timeout bounds the availability question only. Cutting off a running
   * advertisement would abandon somebody midway through something they agreed
   * to watch and then deny them the reward for watching it.
   */
  it("waits as long as the advertisement lasts once one has started", async () => {
    vi.useFakeTimers();
    try {
      let finishAd;
      const win = {
        adBreak: ({ beforeReward, adViewed }) => {
          beforeReward(() => {});
          finishAd = adViewed;
        },
      };
      const asked = requestRewardedAd({ win, timeoutMs: 8000 });

      await vi.advanceTimersByTimeAsync(30000);
      finishAd();

      await expect(asked).resolves.toBe("viewed");
    } finally {
      vi.useRealTimers();
    }
  });

  it("keeps the first outcome when Google calls back more than once", async () => {
    const win = {
      adBreak: ({ beforeReward, adViewed, adBreakDone }) => {
        beforeReward(() => {});
        adViewed();
        adBreakDone({ breakStatus: "viewed" });
      },
    };

    await expect(requestRewardedAd({ win })).resolves.toBe("viewed");
  });
});
