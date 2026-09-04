import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AdSenseProvider } from "@/components/ads/AdSenseProvider.jsx";
import { ConsentProvider, useConsent } from "@/components/consent/ConsentProvider.jsx";
import {
  ADSENSE_SCRIPT_ID,
  CMP_SCRIPT_ID,
  resetAdSenseScriptForTesting,
} from "@/lib/adsense-loader";

const state = vi.hoisted(() => ({
  publicConfig: {
    adsense: { enabled: true, client: "ca-pub-1234567890123456" },
    analytics: { enabled: false, measurementId: null },
    consent: {
      provider: "google",
      analytics: false,
      googleClient: "ca-pub-1234567890123456",
    },
    isLoading: false,
  },
}));

vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({
  ADVERTISING_OFF: { enabled: false, client: null },
  usePublicConfig: () => state.publicConfig,
}));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));

function Probe() {
  const { openPreferences } = useConsent();
  const { pathname } = useLocation();

  return (
    <>
      <span data-testid="path">{pathname}</span>
      <button type="button" onClick={openPreferences}>Preferences</button>
    </>
  );
}

const renderProvider = (path) => render(
  <MemoryRouter initialEntries={[path]}>
    <ConsentProvider>
      <AdSenseProvider>
        <Routes>
          <Route path="*" element={<Probe />} />
        </Routes>
      </AdSenseProvider>
    </ConsentProvider>
  </MemoryRouter>
);

async function finishAdSenseRequest() {
  const showRevocationMessage = vi.fn();
  window.googlefc = { callbackQueue: [], showRevocationMessage };
  document.getElementById(ADSENSE_SCRIPT_ID).dispatchEvent(new Event("load"));

  // Checked by what the queued entry does, not by which reference it is: it
  // calls Google's method through `googlefc` rather than detached from it.
  await waitFor(() => expect(window.googlefc.callbackQueue).toHaveLength(1));
  window.googlefc.callbackQueue[0]();
  expect(showRevocationMessage).toHaveBeenCalledTimes(1);
}

beforeEach(() => {
  resetAdSenseScriptForTesting();
  delete window.googlefc;
});

afterEach(() => {
  resetAdSenseScriptForTesting();
  delete window.googlefc;
});

describe("arbitrating Google's consent-platform loader", () => {
  it("waits for in-flight AdSense before reopening privacy choices", async () => {
    renderProvider("/");
    await waitFor(() => expect(document.getElementById(ADSENSE_SCRIPT_ID)).not.toBeNull());

    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    expect(document.getElementById(CMP_SCRIPT_ID)).toBeNull();
    await finishAdSenseRequest();
    expect(document.getElementById(CMP_SCRIPT_ID)).toBeNull();
  });

  it("waits for in-flight AdSense after leaving a Google-free route", async () => {
    renderProvider("/privacy");
    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    await waitFor(() => expect(screen.getByTestId("path")).toHaveTextContent("/"));
    await waitFor(() => expect(document.getElementById(ADSENSE_SCRIPT_ID)).not.toBeNull());
    expect(document.getElementById(CMP_SCRIPT_ID)).toBeNull();

    await finishAdSenseRequest();
    expect(document.getElementById(CMP_SCRIPT_ID)).toBeNull();
  });
});
