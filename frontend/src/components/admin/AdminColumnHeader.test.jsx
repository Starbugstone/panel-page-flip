import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { AdminColumnHeader } from "./AdminColumnHeader";

describe("AdminColumnHeader", () => {
  it("sorts and applies a filter from the column dropdown", async () => {
    const user = userEvent.setup();
    const onSort = vi.fn();
    const onFilter = vi.fn();
    const { rerender } = render(
      <AdminColumnHeader
        label="Owner"
        sortField="owner"
        filterField="filterOwner"
        sort=""
        direction="DESC"
        onSort={onSort}
        onFilter={onFilter}
      />
    );

    await user.click(screen.getByRole("button", { name: "Owner sort and filter" }));
    await user.click(screen.getByRole("button", { name: /ascending/i }));
    expect(onSort).toHaveBeenCalledWith("owner", "ASC");

    rerender(
      <AdminColumnHeader
        label="Owner"
        sortField="owner"
        filterField="filterOwner"
        sort="owner"
        direction="ASC"
        onSort={onSort}
        onFilter={onFilter}
      />
    );
    await user.click(screen.getByRole("button", { name: "Owner sort and filter" }));
    await user.type(screen.getByRole("searchbox", { name: "Filter Owner" }), "Selina");
    await user.click(screen.getByRole("button", { name: "Apply filter" }));
    expect(onFilter).toHaveBeenCalledWith("filterOwner", "Selina");
  });
});

