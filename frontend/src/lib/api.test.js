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

  it("expires the session even when a 401 body is malformed JSON", async () => {
    const dispatchEvent = vi.fn();
    vi.stubGlobal("window", { dispatchEvent });
    vi.stubGlobal("fetch", vi.fn(async () => new Response("{broken", {
      status: 401, headers: { "Content-Type": "application/json" },
    })));

    await expect(api.get("/api/comics")).rejects.toMatchObject({ status: 401 });
    expect(dispatchEvent).toHaveBeenCalledWith(expect.objectContaining({ type: UNAUTHORIZED_EVENT }));
  });

  it.each(["blob", "response", "text"])("reads JSON errors even when a successful request expects %s", async (responseType) => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({ message: "Comic unavailable" }), {
      status: 403, headers: { "Content-Type": "application/json" },
    })));

    await expect(request("/api/comics/1/download", { responseType })).rejects.toMatchObject({
      status: 403, message: "Comic unavailable", data: { message: "Comic unavailable" },
    });
  });

  it("does not put an upstream HTML error page into a user-facing message", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("<html>Proxy details and internal paths</html>", {
      status: 502, headers: { "Content-Type": "text/html" },
    })));

    await expect(api.get("/api/comics")).rejects.toMatchObject({ message: "Request failed (502)" });
  });

  it("preserves cancellation while a response body is being read", async () => {
    const abort = new DOMException("Request aborted", "AbortError");
    vi.stubGlobal("fetch", vi.fn(async () => ({
      ok: true, status: 200, headers: new Headers({ "content-type": "application/json" }),
      text: () => Promise.reject(abort),
    })));

    await expect(api.get("/api/comics")).rejects.toBe(abort);
  });
});
