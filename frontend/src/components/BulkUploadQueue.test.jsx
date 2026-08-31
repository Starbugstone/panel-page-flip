import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BulkUploadQueue from "./BulkUploadQueue";
import { uploadComicInChunks } from "@/hooks/use-chunked-upload";

const mocks = vi.hoisted(() => ({ toast: vi.fn(), refreshSession: vi.fn().mockResolvedValue(true) }));

vi.mock("@/hooks/use-chunked-upload", () => ({
  createUploadRequestPool: vi.fn(() => (request) => request()),
  uploadComicInChunks: vi.fn(),
}));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-auth", () => ({
  useAuth: () => ({ refreshSession: mocks.refreshSession }),
}));
vi.mock("@/hooks/use-config", () => ({
  useConfig: () => ({ config: { upload: { maxConcurrentUploads: 5, comicFormats: ["cbz"] } } }),
}));
vi.mock("@/hooks/use-library-folders", () => ({
  useLibraryFolders: () => ({ folders: [], isLoading: false }),
}));

const comic = (name) => new File(["x"], name, { type: "application/x-cbz" });

const renderQueue = () => render(<MemoryRouter><BulkUploadQueue /></MemoryRouter>);

const addFiles = async (user, names) => {
  const input = document.querySelector('input[type="file"]');
  await user.upload(input, names.map(comic));
};

describe("taking a file back out of the bulk queue", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.refreshSession.mockResolvedValue(true);
  });

  it("removes a queued file before anything has started", async () => {
    const user = userEvent.setup();
    renderQueue();
    await addFiles(user, ["one.cbz", "two.cbz"]);

    await user.click(screen.getByRole("button", { name: "Remove one.cbz" }));

    expect(screen.queryByText("one.cbz")).not.toBeInTheDocument();
    expect(screen.getByText("two.cbz")).toBeInTheDocument();
  });

  /**
   * The regression this exists for. Removal used to be disabled for the whole
   * run, which is exactly when it is wanted: a folder is dropped in, three of
   * the files were the wrong ones, and the answer was to wait for all of them.
   */
  it("removes a queued file while the rest of the queue is running, and does not upload it", async () => {
    const user = userEvent.setup();
    // Both parallel slots are held open, so the third file is still waiting its
    // turn when it is removed — which is the case that used to be impossible.
    const release = [];
    vi.mocked(uploadComicInChunks).mockImplementation(
      () => new Promise((resolve) => release.push(() => resolve({ comic: { id: release.length } })))
    );

    renderQueue();
    await addFiles(user, ["one.cbz", "two.cbz", "three.cbz"]);
    await user.click(screen.getByRole("button", { name: "Start all" }));
    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(2));

    await user.click(screen.getByRole("button", { name: "Remove three.cbz" }));
    await act(async () => release.forEach((resolve) => resolve()));
    await waitFor(() => expect(screen.getAllByText("Complete")).toHaveLength(2));

    await waitFor(() => expect(screen.getByRole("button", { name: "Start all" })).toBeDisabled());
    const uploaded = vi.mocked(uploadComicInChunks).mock.calls.map(([{ file }]) => file.name);
    expect(uploaded).not.toContain("three.cbz");
    expect(screen.queryByText("three.cbz")).not.toBeInTheDocument();
  });

  /**
   * Removal has to reach the rows React is holding, not the ones this handler
   * was rendered with. A file in flight writes progress into the queue
   * continuously, so a removal that rebuilds the list from the render closure
   * throws away every update that landed since — a file that reached 90% drops
   * back to where it was when the remove button was last drawn.
   */
  it("does not discard a running file's progress when another row is removed", async () => {
    const user = userEvent.setup();
    let report;
    vi.mocked(uploadComicInChunks).mockImplementation(
      ({ file, onProgress }) => new Promise(() => {
        if (file.name === "running.cbz") report = onProgress;
      })
    );

    // Three files for two parallel slots, so the spare is still queued — and so
    // still has a remove button — while the first is uploading.
    renderQueue();
    await addFiles(user, ["running.cbz", "second.cbz", "spare.cbz"]);
    await user.click(screen.getByRole("button", { name: "Start all" }));
    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalled());

    act(() => report(40));
    await screen.findByText("40%");

    // Both in one commit: the progress update is queued before the click, so a
    // handler reading rendered state cannot see it.
    await act(async () => {
      report(90);
      screen.getByRole("button", { name: "Remove spare.cbz" }).click();
    });

    expect(screen.queryByText("spare.cbz")).not.toBeInTheDocument();
    expect(screen.getByText("90%")).toBeInTheDocument();
  });

  it("gives simultaneous files the same global request pool", async () => {
    const user = userEvent.setup();
    const release = [];
    vi.mocked(uploadComicInChunks).mockImplementation(
      () => new Promise((resolve) => release.push(() => resolve({ comic: { id: release.length } })))
    );

    renderQueue();
    await addFiles(user, ["one.cbz", "two.cbz"]);
    await user.click(screen.getByRole("button", { name: "Start all" }));
    await waitFor(() => expect(uploadComicInChunks).toHaveBeenCalledTimes(2));

    const [first, second] = vi.mocked(uploadComicInChunks).mock.calls.map(([options]) => options);
    expect(first.requestPool).toBe(second.requestPool);
    await act(async () => release.forEach((resolve) => resolve()));
    await waitFor(() => expect(screen.getAllByText("Complete")).toHaveLength(2));
  });

  it("offers removal beside retry once a file has failed", async () => {
    const user = userEvent.setup();
    vi.mocked(uploadComicInChunks).mockRejectedValue(new Error("disk full"));

    renderQueue();
    await addFiles(user, ["broken.cbz"]);
    await user.click(screen.getByRole("button", { name: "Start all" }));

    expect(await screen.findByText("Failed")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry broken.cbz" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Remove broken.cbz" }));

    expect(screen.queryByText("broken.cbz")).not.toBeInTheDocument();
  });

  /** An uploaded comic belongs to the library; removing it is not this queue's job. */
  it("offers no removal for a file that has already uploaded", async () => {
    const user = userEvent.setup();
    vi.mocked(uploadComicInChunks).mockResolvedValue({ comic: { id: 7 } });

    renderQueue();
    await addFiles(user, ["done.cbz"]);
    await user.click(screen.getByRole("button", { name: "Start all" }));

    expect(await screen.findByText("Complete")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Remove done.cbz" })).not.toBeInTheDocument();
  });

  it("does not carry a removal over into the next run", async () => {
    const user = userEvent.setup();
    vi.mocked(uploadComicInChunks).mockRejectedValue(new Error("disk full"));

    renderQueue();
    await addFiles(user, ["retry-me.cbz"]);
    await user.click(screen.getByRole("button", { name: "Start all" }));
    await screen.findByText("Failed");

    vi.mocked(uploadComicInChunks).mockResolvedValue({ comic: { id: 9 } });
    await user.click(screen.getByRole("button", { name: "Start all" }));

    expect(await screen.findByText("Complete")).toBeInTheDocument();
  });
});
