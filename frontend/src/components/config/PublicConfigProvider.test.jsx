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

function ConsentProbe() {
  const { consent } = usePublicConfig();
  return <span data-testid="consent">{`${consent.provider ?? "none"}:${consent.analytics}:${consent.googleClient ?? "none"}`}</span>;
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

    render(<PublicConfigProvider><Probe /><ConsentProbe /></PublicConfigProvider>);

    expect(await screen.findByText("ready:off:unknown")).toBeInTheDocument();
    // No provider, so no dialogue and no script. The server is the only
    // authority on whether anything optional is on, and an unanswered question
    // is not a licence to assume either way.
    expect(screen.getByTestId("consent").textContent).toBe("none:false:none");
  });

  /**
   * The consent block is the contract the whole frontend consent story is built
   * on, so each of the four effective states is carried through verbatim.
   * `provider` decides which dialogue exists at all, and a shape change here
   * changes what every provider believes about consent.
   *
   * @see App\Service\ConsentConfiguration
   */
  it.each([
    ["neither", { provider: null, analytics: false, googleClient: null }, "none:false:none"],
    ["AdSense only", { provider: "google", analytics: false, googleClient: "ca-pub-1234567890123456" }, "google:false:ca-pub-1234567890123456"],
    ["Analytics only", { provider: "local", analytics: true, googleClient: null }, "local:true:none"],
    ["both", { provider: "google", analytics: true, googleClient: "ca-pub-1234567890123456" }, "google:true:ca-pub-1234567890123456"],
  ])("carries the %s consent block through to the application", async (_state, consent, expected) => {
    vi.mocked(api.get).mockResolvedValue({ consent });

    render(<PublicConfigProvider><ConsentProbe /></PublicConfigProvider>);

    await waitFor(() => expect(screen.getByTestId("consent").textContent).toBe(expected));
  });

  /**
   * The endpoint used to answer `googleConsent: { enabled, client }`, which
   * modelled every optional Google service as a flavour of AdSense. An old
   * payload must not resolve to a provider.
   */
  it("does not resurrect the old AdSense-shaped consent field", async () => {
    vi.mocked(api.get).mockResolvedValue({
      googleConsent: { enabled: true, client: "ca-pub-1234567890123456" },
    });

    render(<PublicConfigProvider><ConsentProbe /></PublicConfigProvider>);

    await waitFor(() => expect(screen.getByTestId("consent").textContent).toBe("none:false:none"));
  });
});
