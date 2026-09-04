import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { AdminColumnHeader } from "./AdminColumnHeader";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));

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

  it("searches database-wide suggestions after three input characters, including pasted text and aborts the stale request", async () => {
    const pending = [];
    vi.mocked(api.get).mockImplementation((path, options) => new Promise((resolve) => {
      pending.push({ path, options, resolve });
    }));
    render(
      <AdminColumnHeader
        label="Owner"
        filterField="filterOwner"
        suggestionSource="comics/owner"
        onFilter={vi.fn()}
      />
    );

    await userEvent.click(screen.getByRole("button", { name: "Owner sort and filter" }));
    const input = screen.getByRole("searchbox", { name: "Filter Owner" });
    fireEvent.change(input, { target: { value: "se" } });
    fireEvent.keyUp(input, { key: "e" });
    expect(api.get).not.toHaveBeenCalled();

    fireEvent.change(input, { target: { value: "sel" } });
    expect(api.get).toHaveBeenCalledOnce();
    expect(pending[0].path).toBe("/api/admin/table-filter-suggestions/comics/owner?query=sel");
    expect(pending[0].options.signal).toBeInstanceOf(AbortSignal);
    expect(pending[0].options.signal.aborted).toBe(false);
    fireEvent.keyUp(input, { key: "ArrowLeft" });
    expect(api.get).toHaveBeenCalledOnce();

    fireEvent.change(input, { target: { value: "seli" } });
    expect(pending[0].options.signal.aborted).toBe(true);
    expect(api.get).toHaveBeenCalledTimes(2);

    pending[1].resolve({ suggestions: ["Selina Kyle", "selina@example.test"] });
    expect(await screen.findByRole("option", { name: "Selina Kyle" })).toBeInTheDocument();
  });

  it("uses a dropdown for a filter with defined values", async () => {
    const user = userEvent.setup();
    const onFilter = vi.fn();
    const { rerender } = render(
      <AdminColumnHeader
        label="Status"
        filterField="filterStatus"
        filterType="select"
        filterOptions={["Accepted", "Pending", "Declined", "Revoked"]}
        onFilter={onFilter}
      />
    );

    await user.click(screen.getByRole("button", { name: "Status sort and filter" }));
    expect(screen.queryByRole("searchbox", { name: "Filter Status" })).not.toBeInTheDocument();
    const select = screen.getByRole("combobox", { name: "Filter Status" });
    // A portalled Radix Select inside the portalled column Popover dismisses
    // its parent in a real browser and leaves focus on a detached trigger.
    // Keep fixed-value filtering inside the parent with a native select.
    expect(select.tagName).toBe("SELECT");
    await user.selectOptions(select, "Pending");

    expect(onFilter).toHaveBeenCalledWith("filterStatus", "Pending");

    rerender(
      <AdminColumnHeader
        label="Status"
        filterField="filterStatus"
        filterType="select"
        filterOptions={["Accepted", "Pending", "Declined", "Revoked"]}
        filterValue="Pending"
        onFilter={onFilter}
      />
    );
    await user.click(screen.getByRole("button", { name: "Status sort and filter" }));
    await user.selectOptions(screen.getByRole("combobox", { name: "Filter Status" }), "");

    expect(onFilter).toHaveBeenLastCalledWith("filterStatus", "");
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

  it("applies an inclusive storage range from zero to the highest usage", async () => {
    const user = userEvent.setup();
    const onFilter = vi.fn();
    render(
      <AdminColumnHeader
        label="Storage"
        filterField="filterStorage"
        filterType="range"
        filterMax={10 * 1024 ** 2}
        filterStep={1024 ** 2}
        filterFormat="bytes"
        onFilter={onFilter}
      />
    );

    await user.click(screen.getByRole("button", { name: "Storage sort and filter" }));
    expect(screen.getByText("0 B to 10.0 MiB")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Minimum storage"), { target: { value: String(2 * 1024 ** 2) } });
    fireEvent.change(screen.getByLabelText("Maximum storage"), { target: { value: String(9 * 1024 ** 2) } });
    await user.click(screen.getByRole("button", { name: "Apply range" }));

    expect(onFilter).toHaveBeenCalledWith("filterStorage", `${2 * 1024 ** 2}..${9 * 1024 ** 2}`);
  });
});
