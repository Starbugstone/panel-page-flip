import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BulkUploadQueue from "./BulkUploadQueue";
import { DEFAULT_PARALLEL_FILES } from "@/lib/comic-upload";
import { uploadComicInChunks } from "@/hooks/use-chunked-upload";

const mocks = vi.hoisted(() => ({ toast: vi.fn(), refreshSession: vi.fn().mockResolvedValue(true) }));
// Mutable so each test can answer as a differently configured server would.
const { upload } = vi.hoisted(() => ({ upload: { config: {} } }));

vi.mock("@/hooks/use-chunked-upload", () => ({
  createUploadRequestPool: vi.fn(() => (request) => request()),
  uploadComicInChunks: vi.fn(),
}));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ refreshSession: mocks.refreshSession }) }));
vi.mock("@/hooks/use-config", () => ({
  useConfig: () => ({ config: { upload: upload.config } }),
}));
vi.mock("@/hooks/use-library-folders", () => ({
  useLibraryFolders: () => ({ folders: [], isLoading: false }),
}));

const comic = (name) => new File(["x"], name, { type: "application/x-cbz" });

const renderQueue = (config) => {
  upload.config = { maxConcurrentUploads: 5, comicFormats: ["cbz"], ...config };
  return render(<MemoryRouter><BulkUploadQueue /></MemoryRouter>);
};

/** Never resolves, so every started file keeps its slot for the assertion. */
const holdEveryUpload = () => vi.mocked(uploadComicInChunks).mockImplementation(() => new Promise(() => {}));

const startAll = async (user, names) => {
  await user.upload(document.querySelector('input[type="file"]'), names.map(comic));
  await user.click(screen.getByRole("button", { name: "Start all" }));
};

beforeEach(() => {
  vi.clearAllMocks();
  mocks.refreshSession.mockResolvedValue(true);
});

describe("how many comics a bulk upload sends at once", () => {
  /**
   * The regression this exists for. The count used to be a hard-coded 2, so a
   * server raising MAX_PARALLEL_FILE_UPLOADS changed nothing and operators
   * reached for MAX_CONCURRENT_UPLOADS instead — which is the request budget,
   * not the file count, and moved no more comics at a time.
   */
  it("starts as many files as the server configured", async () => {
    const user = userEvent.setup();
    holdEveryUpload();

    renderQueue({ maxParallelFileUploads: 5 });
    await startAll(user, ["one.cbz", "two.cbz", "three.cbz", "four.cbz", "five.cbz"]);

    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(5));
  });

  it("holds later files back until a slot frees up", async () => {
    const user = userEvent.setup();
    const release = [];
    vi.mocked(uploadComicInChunks).mockImplementation(
      () => new Promise((resolve) => release.push(() => resolve({ comic: { id: release.length } })))
    );

    renderQueue({ maxParallelFileUploads: 3 });
    await startAll(user, ["one.cbz", "two.cbz", "three.cbz", "four.cbz"]);
    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(3));

    release.shift()();

    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(4));
  });

  it("never starts more workers than there are files", async () => {
    const user = userEvent.setup();
    holdEveryUpload();

    renderQueue({ maxParallelFileUploads: 5 });
    await startAll(user, ["only.cbz"]);

    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(1));
  });

  it("falls back to the default where the server did not say", async () => {
    const user = userEvent.setup();
    holdEveryUpload();

    renderQueue();
    await startAll(user, ["one.cbz", "two.cbz", "three.cbz"]);

    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(DEFAULT_PARALLEL_FILES));
  });

  it("tells the reader the configured number rather than a number baked into the copy", () => {
    renderQueue({ maxParallelFileUploads: 5 });

    expect(screen.getByText(/5 comics upload at a time/)).toBeInTheDocument();
  });

  it("describes a single-file queue in the singular", () => {
    renderQueue({ maxParallelFileUploads: 1 });

    expect(screen.getByText(/One comic uploads at a time/)).toBeInTheDocument();
  });
});
