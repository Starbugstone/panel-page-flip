import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminMetadataProviders } from "./AdminMetadataProviders";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), put: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));

const providers = (metron = false, comicvine = false) => ({
  providers: [
    { key: "metron", label: "Metron", configured: metron },
    { key: "comicvine", label: "Comic Vine", configured: comicvine },
  ],
});

describe("AdminMetadataProviders", () => {
  beforeEach(() => {
    mocks.toast.mockClear();
    vi.mocked(api.get).mockReset().mockResolvedValue(providers());
    vi.mocked(api.put).mockReset().mockResolvedValue(providers(true, true));
  });

  it("reports which providers are configured", async () => {
    vi.mocked(api.get).mockResolvedValue(providers(true, false));

    render(<AdminMetadataProviders />);

    expect(await screen.findByText("Configured")).toBeInTheDocument();
    expect(screen.getByText("Not configured")).toBeInTheDocument();
  });

  it("sends only the credentials that were typed", async () => {
    const user = userEvent.setup();
    render(<AdminMetadataProviders />);
    await screen.findByText("Metron");

    await user.type(screen.getByLabelText(/comic vine api key/i), "cv-key");
    await user.click(screen.getByRole("button", { name: /save credentials/i }));

    await waitFor(() => expect(api.put).toHaveBeenCalledWith(
      "/api/admin/metadata-providers",
      { comicVineApiKey: "cv-key" }
    ));
  });

  /** A secret must not linger in component state once it has been stored. */
  it("clears the fields after saving", async () => {
    const user = userEvent.setup();
    render(<AdminMetadataProviders />);
    await screen.findByText("Metron");

    const field = screen.getByLabelText(/comic vine api key/i);
    await user.type(field, "cv-key");
    await user.click(screen.getByRole("button", { name: /save credentials/i }));

    await waitFor(() => expect(field).toHaveValue(""));
  });

  it("does not call the server with nothing to say", async () => {
    const user = userEvent.setup();
    render(<AdminMetadataProviders />);
    await screen.findByText("Metron");

    await user.click(screen.getByRole("button", { name: /save credentials/i }));

    expect(api.put).not.toHaveBeenCalled();
    expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Nothing to save" }));
  });

  it("removes a stored credential by sending null", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockResolvedValue(providers(false, true));
    render(<AdminMetadataProviders />);

    await user.click(await screen.findByRole("button", { name: /remove/i }));

    await waitFor(() => expect(api.put).toHaveBeenCalledWith(
      "/api/admin/metadata-providers",
      { comicVineApiKey: null }
    ));
  });

  it("shows why the panel is empty when it cannot load", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("forbidden"));

    render(<AdminMetadataProviders />);

    expect(await screen.findByText(/forbidden/i)).toBeInTheDocument();
  });
});
