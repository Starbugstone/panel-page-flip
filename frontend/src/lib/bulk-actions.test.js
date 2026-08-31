import { describe, expect, it, vi } from "vitest";
import { describeBulkOutcome, pluralize, runBulkAction, summariseLabels } from "./bulk-actions";

const user = (id, name) => ({ id, name });

describe("runBulkAction", () => {
  it("reports every row it managed and every row it did not", async () => {
    const rows = [user(1, "Alice"), user(2, "Bob"), user(3, "Carol")];
    const perform = vi.fn(async (row) => {
      if (row.id === 2) throw new Error("This user still owns comics.");
      return { ok: row.id };
    });

    const outcome = await runBulkAction(rows, perform);

    expect(outcome.succeeded.map(({ item }) => item.id)).toEqual([1, 3]);
    expect(outcome.failed).toEqual([{ item: rows[1], message: "This user still owns comics." }]);
  });

  /**
   * The point of collecting failures instead of throwing: deleting eight
   * accounts where three own comics has to delete the five that can go.
   */
  it("carries on past a failure", async () => {
    const perform = vi.fn().mockRejectedValue(new Error("no"));

    await runBulkAction([user(1), user(2), user(3)], perform);

    expect(perform).toHaveBeenCalledTimes(3);
  });

  it("runs one request at a time", async () => {
    let inFlight = 0;
    let peak = 0;
    const perform = async () => {
      peak = Math.max(peak, ++inFlight);
      await Promise.resolve();
      inFlight -= 1;
    };

    await runBulkAction([user(1), user(2), user(3)], perform);

    expect(peak).toBe(1);
  });

  it("counts progress as it goes, failures included", async () => {
    const seen = [];
    const perform = async (row) => { if (row.id === 2) throw new Error("no"); };

    await runBulkAction([user(1), user(2), user(3)], perform, (done, total) => seen.push([done, total]));

    expect(seen).toEqual([[1, 3], [2, 3], [3, 3]]);
  });

  it("names a failure that arrived without a message", async () => {
    const outcome = await runBulkAction([user(1)], () => Promise.reject(new Error()));

    expect(outcome.failed[0].message).toBe("The request failed");
  });

  it("does nothing at all for an empty selection", async () => {
    const perform = vi.fn();

    expect(await runBulkAction([], perform)).toEqual({ succeeded: [], failed: [] });
    expect(perform).not.toHaveBeenCalled();
  });
});

describe("pluralize", () => {
  it("counts one thing in the singular", () => {
    expect(pluralize(1, "user")).toBe("1 user");
    expect(pluralize(0, "user")).toBe("0 users");
    expect(pluralize(2, "user")).toBe("2 users");
  });

  it("takes an irregular plural", () => {
    expect(pluralize(2, "entry", "entries")).toBe("2 entries");
  });
});

describe("summariseLabels", () => {
  const rows = Array.from({ length: 7 }, (_, index) => user(index, `User ${index}`));

  it("names everything in a short list", () => {
    expect(summariseLabels(rows.slice(0, 2), (row) => row.name)).toBe("User 0, User 1");
  });

  it("counts the tail of a long one rather than printing it", () => {
    expect(summariseLabels(rows, (row) => row.name)).toBe("User 0, User 1, User 2, User 3, User 4 and 2 others");
  });
});

describe("describeBulkOutcome", () => {
  const phrasing = { noun: "user", verbPast: "deleted", labelOf: (row) => row.name };
  const ok = (id, name) => ({ item: user(id, name), result: null });
  const bad = (id, name, message) => ({ item: user(id, name), message });

  it("says plainly what went when everything went", () => {
    const summary = describeBulkOutcome({ succeeded: [ok(1, "Alice"), ok(2, "Bob")], failed: [] }, phrasing);

    expect(summary).toEqual({ title: "2 users deleted" });
  });

  /**
   * A partial run is the normal outcome here, and reporting it as either a
   * success or a failure would be a lie in one direction or the other.
   */
  it("reports a partial run as partial, and names what was refused", () => {
    const summary = describeBulkOutcome({
      succeeded: [ok(1, "Alice"), ok(2, "Bob")],
      failed: [bad(3, "Carol", "This user still owns comics.")],
    }, phrasing);

    expect(summary).toEqual({
      title: "2 of 3 users deleted",
      description: "Carol: This user still owns comics.",
      variant: "destructive",
    });
  });

  it("does not claim a count when nothing landed", () => {
    const summary = describeBulkOutcome({ succeeded: [], failed: [bad(1, "Alice", "no")] }, phrasing);

    expect(summary.title).toBe("Nothing was deleted");
    expect(summary.variant).toBe("destructive");
  });

  it("stops listing failures before the toast becomes a wall of text", () => {
    const failed = Array.from({ length: 5 }, (_, index) => bad(index, `User ${index}`, "no"));

    const summary = describeBulkOutcome({ succeeded: [], failed }, phrasing);

    expect(summary.description).toBe("User 0: no; User 1: no; User 2: no; and 2 more");
  });

  it("falls back to the bare message when a row has no name to give", () => {
    const summary = describeBulkOutcome(
      { succeeded: [], failed: [{ item: user(1), message: "Not found" }] },
      { noun: "share", verbPast: "revoked" }
    );

    expect(summary.description).toBe("Not found");
  });
});
