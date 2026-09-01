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

  it("offers matching text suggestions and applies one without another click", async () => {
    const user = userEvent.setup();
    const onFilter = vi.fn();
    render(
      <AdminColumnHeader
        label="Owner"
        filterField="filterOwner"
        filterSuggestions={["Bruce Wayne", "Selina Kyle", "selina@example.test"]}
        onFilter={onFilter}
      />
    );

    await user.click(screen.getByRole("button", { name: "Owner sort and filter" }));
    await user.type(screen.getByRole("searchbox", { name: "Filter Owner" }), "sel");

    expect(screen.getByRole("listbox", { name: "Owner suggestions" })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "Bruce Wayne" })).not.toBeInTheDocument();
    await user.click(screen.getByRole("option", { name: "Selina Kyle" }));

    expect(onFilter).toHaveBeenCalledWith("filterOwner", "Selina Kyle");
  });

  it("applies an inclusive date range from date controls", async () => {
    const user = userEvent.setup();
    const onFilter = vi.fn();
    render(
      <AdminColumnHeader
        label="Created"
        filterField="filterCreatedAt"
        filterType="date"
        onFilter={onFilter}
      />
    );

    await user.click(screen.getByRole("button", { name: "Created sort and filter" }));
    expect(screen.getByText(/select either boundary/i)).toBeInTheDocument();
    expect(document.querySelector('input[type="date"]')).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Today" }));
    await user.click(screen.getByRole("button", { name: "Apply range" }));

    const now = new Date();
    const today = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, "0"),
      String(now.getDate()).padStart(2, "0"),
    ].join("-");
    expect(onFilter).toHaveBeenCalledWith("filterCreatedAt", `${today}..${today}`);
  });
});
