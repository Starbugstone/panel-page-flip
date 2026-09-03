import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import UploadComicForm from "./UploadComicForm";

const mocks = vi.hoisted(() => ({
  toast: vi.fn(),
  refreshSession: vi.fn(),
  start: vi.fn(),
  cancel: vi.fn(),
  uploadState: { status: "idle", progress: 0, error: null },
}));

vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ refreshSession: mocks.refreshSession }) }));
vi.mock("@/hooks/use-config.jsx", () => ({
  useConfig: () => ({ config: { upload: { comicFormats: ["cbz"], maxConcurrentUploads: 2 } } }),
}));
vi.mock("@/hooks/use-tags.jsx", () => ({
  useTags: () => ({ tags: [], addTagToCache: vi.fn() }),
}));
vi.mock("@/hooks/use-library-folders", () => ({
  useLibraryFolders: () => ({
    folders: [{ id: 7, name: "Manga" }],
    isLoading: false,
    createFolder: vi.fn(),
  }),
}));
vi.mock("@/hooks/use-chunked-upload", () => ({
  useChunkedUpload: () => ({
    start: mocks.start,
    cancel: mocks.cancel,
    ...mocks.uploadState,
  }),
}));
vi.mock("@/components/library/FolderDestinationSelect", () => ({
  FolderDestinationSelect: ({ value }) => <div>Destination {value ?? "root"}</div>,
}));
vi.mock("@/components/TagCombobox", () => ({
  TagCombobox: ({ id, value, onChange }) => (
    <input id={id} aria-label="Add tags" value={value} onChange={(event) => onChange(event.target.value)} />
  ),
}));

describe("UploadComicForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.refreshSession.mockResolvedValue(true);
    Object.assign(mocks.uploadState, { status: "idle", progress: 0, error: null });
  });

  it("keeps the specific failure visible after an upload stops", () => {
    Object.assign(mocks.uploadState, {
      status: "error",
      progress: 95,
      error: new Error("PDF inspection failed."),
    });

    render(
      <MemoryRouter>
        <UploadComicForm />
      </MemoryRouter>
    );

    expect(screen.getByText("Upload failed")).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("PDF inspection failed.");
  });

  it("validates the selected format and sends the requested destination", async () => {
    const user = userEvent.setup();
    mocks.start.mockRejectedValue(new Error("Archive is malformed"));
    render(
      <MemoryRouter initialEntries={["/upload?folder=7"]}>
        <UploadComicForm />
      </MemoryRouter>
    );

    await user.upload(
      screen.getByLabelText("Comic File (CBZ)"),
      new File(["comic"], "Issue 01.cbz", { type: "application/zip" }),
    );
    expect(screen.getByLabelText("Title")).toHaveValue("Issue 01");
    expect(screen.getByText("Destination 7")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Upload Comic" }));

    await waitFor(() => expect(mocks.start).toHaveBeenCalledWith(
      expect.objectContaining({ name: "Issue 01.cbz" }),
      { title: "Issue 01", author: "", tags: [], folderId: 7 },
    ));
    expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Upload failed",
      description: "Archive is malformed",
      variant: "destructive",
    }));
  });

  it("wraps a long selected filename and uses compact card padding on a phone", async () => {
    const user = userEvent.setup();
    const name = `${"a-very-long-unbroken-comic-filename-".repeat(3)}issue.cbz`;
    render(
      <MemoryRouter>
        <UploadComicForm />
      </MemoryRouter>,
    );

    await user.upload(
      screen.getByLabelText("Comic File (CBZ)"),
      new File(["comic"], name, { type: "application/zip" }),
    );

    expect(screen.getByText(name)).toHaveClass("min-w-0", "break-all");
    expect(screen.getByRole("heading", { name: "Upload New Comic" }).parentElement).toHaveClass(
      "p-4",
      "sm:p-6",
    );
    expect(document.querySelector("#upload-form")?.parentElement).toHaveClass(
      "px-4",
      "pb-4",
      "sm:px-6",
      "sm:pb-6",
    );
  });
});
