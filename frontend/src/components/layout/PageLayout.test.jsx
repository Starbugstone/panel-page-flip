import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { PageLayout, PageHeader, PageLoading } from "./PageLayout";

describe("shared page presentation", () => {
  it("keeps the title, description and actions together with one primary heading", () => {
    render(<PageLayout width="reading"><PageHeader title="Settings" description="Manage your account" actions={<button>Save</button>} /><p>Content</p></PageLayout>);
    expect(screen.getByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
    expect(screen.getByText("Manage your account")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Save" })).toBeInTheDocument();
    expect(screen.getByText("Content").parentElement).toHaveClass("page-layout", "max-w-4xl");
  });

  it("announces loading with a visible explanation", () => {
    render(<PageLoading label="Loading your library…" />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading your library…");
  });
});
