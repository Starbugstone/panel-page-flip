import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiError, UNAUTHORIZED_EVENT, api, request } from "./api";

describe("api.request", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("parses JSON and attaches CSRF headers on mutating requests", async () => {
    globalThis.document = { cookie: "XSRF-TOKEN=secret" };
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true }), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    }));
    vi.stubGlobal("fetch", fetchMock);

    await expect(api.post("/api/tags", { name: "Marvel" })).resolves.toEqual({ ok: true });

    const [, options] = fetchMock.mock.calls[0];
    expect(options.method).toBe("POST");
    expect(options.headers.get("X-XSRF-TOKEN")).toBe("secret");
    expect(options.body).toBe(JSON.stringify({ name: "Marvel" }));
  });

  it("returns null for an empty 204", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response(null, { status: 204 })));

    await expect(api.delete("/api/tags/1")).resolves.toBeNull();
  });

  it("dispatches unauthorized and throws ApiError on 401", async () => {
    const dispatched = [];
    globalThis.window = {
      dispatchEvent: (event) => {
        dispatched.push(event.type);
        return true;
      },
    };
    vi.stubGlobal("fetch", vi.fn(async () => new Response(
      JSON.stringify({ message: "Please sign in" }),
      { status: 401, headers: { "Content-Type": "application/json" } },
    )));

    await expect(api.get("/api/me")).rejects.toMatchObject({
      name: "ApiError",
      status: 401,
      message: "Please sign in",
    });
    expect(dispatched).toEqual([UNAUTHORIZED_EVENT]);
  });

  it("does not notify when notifyUnauthorized is false", async () => {
    const dispatchEvent = vi.fn();
    globalThis.window = { dispatchEvent };
    vi.stubGlobal("fetch", vi.fn(async () => new Response(
      JSON.stringify({ message: "Please sign in" }),
      { status: 401, headers: { "Content-Type": "application/json" } },
    )));

    await expect(request("/api/me", { notifyUnauthorized: false })).rejects.toBeInstanceOf(ApiError);
    expect(dispatchEvent).not.toHaveBeenCalled();
  });

  it("wraps a network failure", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }));

    await expect(api.get("/api/me")).rejects.toMatchObject({
      name: "ApiError",
      message: "Unable to reach the server",
    });
  });
});
