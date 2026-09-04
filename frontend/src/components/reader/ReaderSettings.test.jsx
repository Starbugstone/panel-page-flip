import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ReaderSettings } from "./ReaderSettings";
import { DEFAULT_READER_PREFERENCES } from "@/lib/reader-preferences";

const { consent } = vi.hoisted(() => ({
  consent: { provider: null, openPreferences: vi.fn() },
}));

vi.mock("@/components/consent/ConsentProvider.jsx", () => ({
  useConsent: () => ({
    provider: consent.provider,
    canOpenPreferences: consent.provider !== null,
    openPreferences: consent.openPreferences,
  }),
}));

beforeEach(() => {
  consent.provider = null;
  consent.openPreferences = vi.fn();
});

describe("reader settings persistence status", () => {
  it("offers an explicit close control for the long settings panel", async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    render(
      <ReaderSettings
        settings={DEFAULT_READER_PREFERENCES.settings}
        isLoaded
        isSaving={false}
        hasSyncError={false}
        contextLabel="this desktop in landscape"
        hasOverride={false}
        onChange={vi.fn()}
        onOverrideChange={vi.fn()}
        onOpenChange={onOpenChange}
        onReset={vi.fn()}
      />
    );

    await user.click(screen.getByRole("button", { name: "Reader settings" }));
    expect(screen.getByRole("dialog", { name: "Reader settings" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Close reader settings" }));

    expect(screen.queryByText("Preferences sync across devices")).not.toBeInTheDocument();
    expect(onOpenChange).toHaveBeenLastCalledWith(false);
  });

  it("keeps consent withdrawal reachable without putting a footer over the reader", async () => {
    consent.provider = "google";
    render(
      <ReaderSettings
        settings={DEFAULT_READER_PREFERENCES.settings}
        isLoaded
        isSaving={false}
        hasSyncError={false}
        contextLabel="this phone in portrait"
        hasOverride={false}
        onChange={vi.fn()}
        onOverrideChange={vi.fn()}
        onOpenChange={vi.fn()}
        onReset={vi.fn()}
      />
    );

    await userEvent.click(screen.getByRole("button", { name: "Reader settings" }));

    expect(screen.getByRole("button", { name: "Privacy choices" })).toBeInTheDocument();
  });
  it("does not claim a failed optimistic preference was saved across devices", async () => {
    const user = userEvent.setup();
    render(
      <ReaderSettings
        settings={DEFAULT_READER_PREFERENCES.settings}
        isLoaded
        isSaving={false}
        hasSyncError
        contextLabel="this phone in portrait"
        hasOverride={false}
        onChange={vi.fn()}
        onOverrideChange={vi.fn()}
        onOpenChange={vi.fn()}
        onReset={vi.fn()}
      />
    );

    await user.click(screen.getByRole("button", { name: "Reader settings" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Not synced with your account");
    expect(screen.queryByText("Saved across devices")).not.toBeInTheDocument();
  });

  it("sets the current zoom with a range control", async () => {
    const user = userEvent.setup();
    const onZoomChange = vi.fn();
    render(
      <ReaderSettings
        settings={{ ...DEFAULT_READER_PREFERENCES.settings, mode: "single" }}
        zoomLevel={1.5}
        isLoaded
        isSaving={false}
        hasSyncError={false}
        contextLabel="this desktop in landscape"
        hasOverride={false}
        onChange={vi.fn()}
        onZoomChange={onZoomChange}
        onOverrideChange={vi.fn()}
        onOpenChange={vi.fn()}
        onReset={vi.fn()}
      />
    );

    await user.click(screen.getByRole("button", { name: "Reader settings" }));
    const zoom = screen.getByRole("slider", { name: "Zoom level" });
    expect(zoom).toHaveValue("150");

    fireEvent.change(zoom, { target: { value: "275" } });
    expect(onZoomChange).toHaveBeenCalledWith(2.75);
  });

  /**
   * `canZoom` asks whether there is a comic to zoom, not whether this mode
   * supports zooming — every mode does. The copy said otherwise, and the only
   * comic it ever appeared for was one with no pages, where naming reading
   * modes explains nothing.
   */
  it("explains an unavailable zoom by the comic, not by the reading mode", async () => {
    const user = userEvent.setup();
    render(
      <ReaderSettings
        settings={{ ...DEFAULT_READER_PREFERENCES.settings, mode: "continuous" }}
        zoomLevel={1}
        canZoom={false}
        isLoaded
        isSaving={false}
        hasSyncError={false}
        contextLabel="this desktop in landscape"
        hasOverride={false}
        onChange={vi.fn()}
        onZoomChange={vi.fn()}
        onOverrideChange={vi.fn()}
        onOpenChange={vi.fn()}
        onReset={vi.fn()}
      />
    );

    await user.click(screen.getByRole("button", { name: "Reader settings" }));

    expect(screen.getByRole("slider", { name: "Zoom level" })).toBeDisabled();
    expect(screen.getByText("Zoom becomes available once the comic has pages.")).toBeInTheDocument();
    expect(screen.queryByText(/available in single-page and two-page modes/i)).not.toBeInTheDocument();
  });

  /** Continuous mode zooms too — by widening the column rather than the page. */
  it("offers a working zoom in continuous mode", async () => {
    const user = userEvent.setup();
    const onZoomChange = vi.fn();
    render(
      <ReaderSettings
        settings={{ ...DEFAULT_READER_PREFERENCES.settings, mode: "continuous" }}
        zoomLevel={1}
        continuousZoom
        isLoaded
        isSaving={false}
        hasSyncError={false}
        contextLabel="this desktop in landscape"
        hasOverride={false}
        onChange={vi.fn()}
        onZoomChange={onZoomChange}
        onOverrideChange={vi.fn()}
        onOpenChange={vi.fn()}
        onReset={vi.fn()}
      />
    );

    await user.click(screen.getByRole("button", { name: "Reader settings" }));
    const zoom = screen.getByRole("slider", { name: "Zoom level" });
    expect(zoom).toBeEnabled();
    expect(screen.getByText(/width of every page while keeping continuous scrolling/i)).toBeInTheDocument();

    fireEvent.change(zoom, { target: { value: "175" } });
    expect(onZoomChange).toHaveBeenCalledWith(1.75);
  });
});
