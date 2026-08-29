import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ReaderSettings } from "./ReaderSettings";
import { DEFAULT_READER_PREFERENCES } from "@/lib/reader-preferences";

describe("reader settings persistence status", () => {
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
