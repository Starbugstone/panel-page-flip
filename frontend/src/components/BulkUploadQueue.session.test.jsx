import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BulkUploadQueue from "./BulkUploadQueue";
import { uploadComicInChunks } from "@/hooks/use-chunked-upload";
import { closeBulkUploadSession } from "@/lib/bulk-upload-session";

const mocks = vi.hoisted(() => ({ toast: vi.fn(), refreshSession: vi.fn().mockResolvedValue(true) }));
const { adSense } = vi.hoisted(() => ({ adSense: { config: { enabled: false, client: null } } }));

vi.mock("@/hooks/use-chunked-upload", () => ({
  createUploadRequestPool: vi.fn(() => (request) => request()),
  uploadComicInChunks: vi.fn(),
}));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ refreshSession: mocks.refreshSession }) }));
vi.mock("@/hooks/use-config", () => ({
  useConfig: () => ({ config: { upload: { maxConcurrentUploads: 5, comicFormats: ["cbz"] } } }),
}));
vi.mock("@/hooks/use-library-folders", () => ({
  useLibraryFolders: () => ({ folders: [], isLoading: false }),
}));
vi.mock("@/lib/bulk-upload-session", () => ({
  closeBulkUploadSession: vi.fn(() => Promise.resolve(null)),
}));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({ config: adSense.config, isLoading: false, scriptStatus: "idle" }),
}));

const CLIENT = "ca-pub-1234567890123456";

const comic = (name) => new File(["x"], name, { type: "application/x-cbz" });
const renderQueue = () => render(<MemoryRouter><BulkUploadQueue /></MemoryRouter>);

const runBatch = async (user, names) => {
  await user.upload(document.querySelector('input[type="file"]'), names.map(comic));
  await user.click(screen.getByRole("button", { name: /start all/i }));
};

beforeEach(() => {
  vi.clearAllMocks();
  mocks.refreshSession.mockResolvedValue(true);
  adSense.config = { enabled: true, client: CLIENT };
  vi.mocked(uploadComicInChunks).mockResolvedValue({ comic: { id: 1 } });
});

/**
 * One rewarded advertisement buys one batch. Where the session ends decides how
 * often somebody is asked to watch another, so the boundary is behaviour, not
 * bookkeeping.
 */
describe("ending the rewarded bulk-upload session", () => {
  it("closes the session once the batch has finished cleanly", async () => {
    const user = userEvent.setup();
    renderQueue();

    await runBatch(user, ["one.cbz", "two.cbz"]);

    await waitFor(() => expect(closeBulkUploadSession).toHaveBeenCalledOnce());
  });

  /**
   * Issue #73: "An upload failure should not force the user to re-watch an
   * advertisement unnecessarily." Closing the session with files still failed
   * means retrying them costs a second advertisement for the batch the first
   * one already paid for.
   */
  it("keeps the session open while a file still needs retrying", async () => {
    vi.mocked(uploadComicInChunks)
      .mockResolvedValueOnce({ comic: { id: 1 } })
      .mockRejectedValueOnce(new Error("Upload failed"));

    const user = userEvent.setup();
    renderQueue();

    await runBatch(user, ["one.cbz", "two.cbz"]);

    await waitFor(() => expect(screen.getByText("Failed")).toBeInTheDocument());
    expect(closeBulkUploadSession).not.toHaveBeenCalled();
  });

  /**
   * A retry that rescues the last failure ends the batch as surely as a clean
   * run does. Leaving the session open here hands the *next* batch a free pass
   * until it expires two hours later, which is one advertisement paying for
   * two batches.
   */
  it("closes the session when a retry finishes the last outstanding file", async () => {
    vi.mocked(uploadComicInChunks)
      .mockResolvedValueOnce({ comic: { id: 1 } })
      .mockRejectedValueOnce(new Error("Upload failed"))
      .mockResolvedValueOnce({ comic: { id: 2 } });

    const user = userEvent.setup();
    renderQueue();

    await runBatch(user, ["one.cbz", "two.cbz"]);
    await waitFor(() => expect(screen.getByText("Failed")).toBeInTheDocument());
    expect(closeBulkUploadSession).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: /retry two\.cbz/i }));

    await waitFor(() => expect(closeBulkUploadSession).toHaveBeenCalledOnce());
  });

  /**
   * A row whose title has been cleared is not uploadable, so "Start all" leaves
   * it behind. That is still work outstanding in the batch: closing the session
   * over it charges a second advertisement to upload the file the first one
   * already paid for.
   */
  it("keeps the session open while a file is left behind for having no title", async () => {
    const user = userEvent.setup();
    renderQueue();

    await user.upload(document.querySelector('input[type="file"]'), [comic("one.cbz"), comic("two.cbz")]);
    await user.clear(screen.getByRole("textbox", { name: /title for two\.cbz/i }));
    await user.click(screen.getByRole("button", { name: /start all/i }));

    await waitFor(() => expect(screen.getByText("Complete")).toBeInTheDocument());
    expect(uploadComicInChunks).toHaveBeenCalledOnce();
    expect(closeBulkUploadSession).not.toHaveBeenCalled();
  });

  /** A retry that fails again leaves the batch unfinished, so the offer stands. */
  it("keeps the session open when a retry fails again", async () => {
    vi.mocked(uploadComicInChunks)
      .mockResolvedValueOnce({ comic: { id: 1 } })
      .mockRejectedValueOnce(new Error("Upload failed"))
      .mockRejectedValueOnce(new Error("Upload failed again"));

    const user = userEvent.setup();
    renderQueue();

    await runBatch(user, ["one.cbz", "two.cbz"]);
    await waitFor(() => expect(screen.getByText("Failed")).toBeInTheDocument());

    await user.click(screen.getByRole("button", { name: /retry two\.cbz/i }));

    await waitFor(() => expect(screen.getByText("Upload failed again")).toBeInTheDocument());
    expect(closeBulkUploadSession).not.toHaveBeenCalled();
  });

  /**
   * The gate never opened a session on an installation with advertising off, so
   * this would be an authenticated request to delete something that does not
   * exist, after every batch, for every self-hosted user.
   */
  it("asks for nothing where the installation shows no advertising", async () => {
    adSense.config = { enabled: false, client: null };

    const user = userEvent.setup();
    renderQueue();

    await runBatch(user, ["one.cbz"]);

    await waitFor(() => expect(screen.getByText("Complete")).toBeInTheDocument());
    expect(closeBulkUploadSession).not.toHaveBeenCalled();
  });
});
