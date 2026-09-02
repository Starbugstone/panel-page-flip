import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { AdminPagination } from "./AdminPagination";

describe("AdminPagination responsive layout", () => {
  it("stacks both control groups on user-facing narrow pages", () => {
    render(
      <AdminPagination
        pagination={{ page: 1, limit: 25, totalItems: 30, totalPages: 2 }}
        itemCount={25}
        onPageChange={vi.fn()}
        onLimitChange={vi.fn()}
        label="shared comics"
      />,
    );

    const previous = screen.getByRole("button", { name: "Previous page" });
    const controls = previous.parentElement.parentElement;
    expect(controls).toHaveClass("flex-col", "items-stretch", "sm:flex-row", "sm:items-center");
    expect(previous.parentElement).toHaveClass("justify-between", "sm:justify-start");
  });
});
