import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PublicConfigProvider, usePublicConfig } from "./PublicConfigProvider.jsx";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn() } }));

function Probe() {
  const { turnstile, legal, isLoading } = usePublicConfig();
  return <span>{`${isLoading ? "loading" : "ready"}:${turnstile.enabled ? turnstile.siteKey : "off"}:${legal.legalEmail ?? "unknown"}`}</span>;
}

beforeEach(() => vi.clearAllMocks());

describe("PublicConfigProvider", () => {
  it("owns one runtime request and publishes Turnstile without a secret", async () => {
    vi.mocked(api.get).mockResolvedValue({
      turnstile: { enabled: true, siteKey: "public-site-key" },
      legalEmail: "legal@example.test",
    });

    render(<PublicConfigProvider><Probe /><Probe /></PublicConfigProvider>);

    await waitFor(() => expect(screen.getAllByText("ready:public-site-key:legal@example.test")).toHaveLength(2));
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledWith("/api/public-config", { notifyUnauthorized: false });
  });

  it("fails closed to disabled optional services when runtime config is unavailable", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("offline"));

    render(<PublicConfigProvider><Probe /></PublicConfigProvider>);

    expect(await screen.findByText("ready:off:unknown")).toBeInTheDocument();
  });
});
