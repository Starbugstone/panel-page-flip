import { describe, expect, it } from "vitest";

import {
  COVER_REQUEST_LIMIT,
  coverRetryDelay,
  coverUrlForAttempt,
  createRequestSlots,
} from "./cover-loading";

const settled = () => new Promise((resolve) => { setTimeout(resolve, 0); });

/** Ask for `count` tickets and report which of them were granted. */
async function acquireMany(slots, count) {
  const tickets = [...Array(count)].map(() => slots.acquire());
  const outcomes = tickets.map((ticket) => {
    const record = { ticket, granted: false };
    ticket.granted.then((granted) => { record.granted = granted; });
    return record;
  });
  await settled();

  return outcomes;
}

describe("cover request slots", () => {
  it("lets a screenful start and makes the rest wait", async () => {
    const slots = createRequestSlots({ limit: 3 });

    const outcomes = await acquireMany(slots, 10);

    expect(outcomes.filter(({ granted }) => granted)).toHaveLength(3);
    expect(slots.activeCount).toBe(3);
    expect(slots.waitingCount).toBe(7);
  });

  it("hands a freed slot to whoever asked first", async () => {
    const slots = createRequestSlots({ limit: 2 });
    const outcomes = await acquireMany(slots, 4);

    outcomes[0].ticket.release();
    await settled();

    expect(outcomes.map(({ granted }) => granted)).toEqual([true, true, true, false]);
    expect(slots.activeCount).toBe(2);
  });

  it("gives up a ticket that was released before its turn came", async () => {
    const slots = createRequestSlots({ limit: 1 });
    const outcomes = await acquireMany(slots, 3);

    outcomes[1].ticket.release();
    await settled();

    expect(slots.waitingCount).toBe(1);

    outcomes[0].ticket.release();
    await settled();

    // The abandoned ticket never starts, and the one behind it goes instead.
    expect(outcomes[1].granted).toBe(false);
    expect(outcomes[2].granted).toBe(true);
  });

  it("counts a slot back once however many times it is released", async () => {
    const slots = createRequestSlots({ limit: 2 });
    const outcomes = await acquireMany(slots, 4);

    outcomes[0].ticket.release();
    outcomes[0].ticket.release();
    await settled();

    expect(slots.activeCount).toBe(2);
    expect(outcomes[3].granted).toBe(false);
  });

  it("caps the whole library, not each card", () => {
    expect(COVER_REQUEST_LIMIT).toBeLessThan(10);
  });
});

describe("retrying a cover", () => {
  it("waits longer after each failure", () => {
    const delays = [0, 1, 2, 3].map((attempt) => coverRetryDelay(attempt, () => 1));

    expect(delays).toEqual([...delays].sort((a, b) => a - b));
    expect(new Set(delays).size).toBe(delays.length);
  });

  it("stops growing, so a cover that comes back is not waited on for a minute", () => {
    expect(coverRetryDelay(20, () => 1)).toBeLessThanOrEqual(8000);
  });

  it("spreads a screenful of failures out instead of repeating the burst", () => {
    expect(coverRetryDelay(2, () => 0)).toBeLessThan(coverRetryDelay(2, () => 1));
  });

  it("asks for a different URL, because the browser is holding the failed one", () => {
    expect(coverUrlForAttempt("/api/comics/cover/1/2/a.jpg", 0)).toBe("/api/comics/cover/1/2/a.jpg");
    expect(coverUrlForAttempt("/api/comics/cover/1/2/a.jpg", 1)).toBe("/api/comics/cover/1/2/a.jpg?retry=1");
    expect(coverUrlForAttempt("/api/comics/cover/1/2/a.jpg?v=3", 2)).toBe("/api/comics/cover/1/2/a.jpg?v=3&retry=2");
  });

  it("has nothing to ask for when there is no cover", () => {
    expect(coverUrlForAttempt(null, 2)).toBe(null);
  });
});
