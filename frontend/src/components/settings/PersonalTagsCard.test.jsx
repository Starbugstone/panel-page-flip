import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

const notifications = vi.hoisted(() => ({ toast: vi.fn(), fetchTags: vi.fn() }));

vi.mock("@/lib/api", () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: notifications.toast }) }));
vi.mock("@/hooks/use-tags", () => ({ useTags: () => ({ fetchTags: notifications.fetchTags }) }));

import { api } from "@/lib/api";
import { PersonalTagsCard } from "./PersonalTagsCard";

describe("PersonalTagsCard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ tags: [] });
    notifications.fetchTags.mockResolvedValue(undefined);
  });

  it("loads only personal tags and deletes the selected tag", async () => {
    const user = userEvent.setup();
    api.get.mockResolvedValue({
      tags: [
        { id: 1, name: "Global", comicCount: 8, isGlobal: true },
        { id: 2, name: "Manga", comicCount: 3, isGlobal: false },
      ],
    });
    api.delete.mockResolvedValue({});

    render(<PersonalTagsCard />);

    expect(await screen.findByText("Manga")).toBeInTheDocument();
    expect(screen.queryByText("Global")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Delete Manga" }));
    await user.click(screen.getByRole("button", { name: "Delete" }));

    expect(api.delete).toHaveBeenCalledWith("/api/tags/2");
    await waitFor(() => expect(screen.queryByText("Manga")).not.toBeInTheDocument());
    expect(notifications.fetchTags).toHaveBeenCalledWith(true);
  });

  it("creates a trimmed tag and refreshes the shared tag cache", async () => {
    const user = userEvent.setup();
    api.post.mockResolvedValue({ tag: { id: 3, name: "European", comicCount: 0 } });
    render(<PersonalTagsCard />);

    await screen.findByText("No personal tags yet");
    await user.click(screen.getByRole("button", { name: "Add tag" }));
    await user.type(screen.getByLabelText("Name"), "  European  ");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(api.post).toHaveBeenCalledWith("/api/tags", { name: "European" });
    expect(await screen.findByText("European")).toBeInTheDocument();
    expect(notifications.toast).toHaveBeenCalledWith({ title: "Tag created" });
  });

  it("rejects a duplicate name without making a request", async () => {
    const user = userEvent.setup();
    api.get.mockResolvedValue({ tags: [{ id: 2, name: "Manga", comicCount: 3 }] });
    render(<PersonalTagsCard />);

    expect(await screen.findByText("Manga")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Add tag" }));
    await user.type(screen.getByLabelText("Name"), "manga");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(api.post).not.toHaveBeenCalled();
    expect(notifications.toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Tag already exists" }));
  });

  it("renames a tag in place", async () => {
    const user = userEvent.setup();
    api.get.mockResolvedValue({ tags: [{ id: 2, name: "Manga", comicCount: 3 }] });
    api.put.mockResolvedValue({ tag: { id: 2, name: "Graphic novels", comicCount: 3 } });
    render(<PersonalTagsCard />);

    await user.click(await screen.findByRole("button", { name: "Edit Manga" }));
    const input = screen.getByLabelText("Name");
    await user.clear(input);
    await user.type(input, "Graphic novels");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(api.put).toHaveBeenCalledWith("/api/tags/2", { name: "Graphic novels" });
    expect(await screen.findByText("Graphic novels")).toBeInTheDocument();
  });
});
