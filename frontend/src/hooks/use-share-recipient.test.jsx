import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useShareRecipient } from "@/hooks/use-share-recipient";
import { api } from "@/lib/api";
import { MODES, TARGETS } from "@/lib/sharing-workflow";

vi.mock("@/lib/api", () => ({ api: { post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));

const alice = { username: "Alice", name: "", label: "@Alice" };
const bob = { username: "Bobby", name: "", label: "@Bobby" };

/** One response per lookup, settled by the test in whatever order it needs. */
function deferLookups() {
  const pending = [];
  api.post.mockImplementation(() => new Promise((resolve, reject) => {
    pending.push({ resolve, reject });
  }));

  return pending;
}

function renderRecipient(initialUsername = "Alice") {
  const onError = vi.fn();
  const { result } = renderHook(() => useShareRecipient({
    initialRecipient: "",
    initialUsername,
    initialUserCode: "",
    initialResolved: null,
    mode: MODES.DIRECT,
    onError,
  }));

  return { result, onError };
}

describe("useShareRecipient", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("confirms the name it looked up while that name is still the one in the field", async () => {
    const lookups = deferLookups();
    const { result } = renderRecipient();

    let lookup;
    act(() => { lookup = result.current.resolveRecipient(); });
    expect(result.current.isResolving).toBe(true);

    await act(async () => {
      lookups[0].resolve({ recipient: alice });
      await lookup;
    });

    expect(result.current.resolved).toEqual(alice);
    expect(result.current.recipientChosen).toBe(true);
    expect(result.current.label).toBe("@Alice");
    expect(result.current.isResolving).toBe(false);
  });

  it("ignores a lookup whose username has since changed", async () => {
    const lookups = deferLookups();
    const { result } = renderRecipient();

    let lookup;
    act(() => { lookup = result.current.resolveRecipient(); });
    act(() => { result.current.setUsername("Bobby"); });
    // The sender is free to check the new name straight away.
    expect(result.current.isResolving).toBe(false);

    await act(async () => {
      lookups[0].resolve({ recipient: alice });
      await lookup;
    });

    expect(result.current.resolved).toBeNull();
    expect(result.current.recipientChosen).toBe(false);
    expect(result.current.confirmationPending).toBe(true);
    expect(result.current.label).toBe("@Bobby");
    expect(result.current.payload()).toEqual({ username: "Bobby" });
  });

  it("ignores a lookup whose target has since changed", async () => {
    const lookups = deferLookups();
    const { result } = renderRecipient();

    let lookup;
    act(() => { lookup = result.current.resolveRecipient(); });
    act(() => { result.current.setTarget(TARGETS.CODE); });

    await act(async () => {
      lookups[0].resolve({ recipient: alice });
      await lookup;
    });

    expect(result.current.resolved).toBeNull();
    expect(result.current.label).not.toContain("Alice");
  });

  it("does not let an older lookup overwrite a newer one", async () => {
    const lookups = deferLookups();
    const { result } = renderRecipient();

    let first;
    act(() => { first = result.current.resolveRecipient(); });
    act(() => { result.current.setUsername("Bobby"); });
    let second;
    act(() => { second = result.current.resolveRecipient(); });

    await act(async () => {
      lookups[1].resolve({ recipient: bob });
      await second;
    });
    await act(async () => {
      lookups[0].resolve({ recipient: alice });
      await first;
    });

    expect(result.current.resolved).toEqual(bob);
    expect(result.current.label).toBe("@Bobby");
  });

  it("does not report the failure of a lookup nobody is waiting for", async () => {
    const lookups = deferLookups();
    const { result, onError } = renderRecipient();

    let lookup;
    act(() => { lookup = result.current.resolveRecipient(); });
    onError.mockClear();
    act(() => { result.current.setUsername("Bobby"); });

    await act(async () => {
      lookups[0].reject(new Error("That recipient could not be found."));
      await lookup;
    });

    expect(onError).not.toHaveBeenCalled();
  });

  it("keeps a recipient picked from history over a lookup still in flight", async () => {
    const lookups = deferLookups();
    const { result } = renderRecipient();

    let lookup;
    act(() => { lookup = result.current.resolveRecipient(); });
    act(() => {
      result.current.setUsername("Bobby");
      result.current.setResolved(bob);
    });

    await act(async () => {
      lookups[0].resolve({ recipient: alice });
      await lookup;
    });

    expect(result.current.resolved).toEqual(bob);
  });
});
