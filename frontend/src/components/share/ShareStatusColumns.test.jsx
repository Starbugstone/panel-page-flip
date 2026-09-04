import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ShareStatusColumns } from "./ShareStatusColumns";
import { Table, TableHeader, TableRow } from "@/components/ui/table";

describe("shared status and date controls", () => {
  it("applies a status filter and sorts by the shared date", async () => {
    const onFilter = vi.fn();
    const onSort = vi.fn();
    render(
      <Table><TableHeader><TableRow>
        <ShareStatusColumns tableControls={{
          columnFilters: { filterStatus: "Pending" },
          headerProps: { sort: "createdAt", direction: "DESC", onFilter, onSort },
        }} />
      </TableRow></TableHeader></Table>
    );

    await userEvent.click(screen.getByRole("button", { name: "Status sort and filter" }));
    const status = screen.getByRole("combobox", { name: "Filter Status" });
    expect(status).toHaveValue("Pending");
    await userEvent.selectOptions(status, "Accepted");
    expect(onFilter).toHaveBeenCalledWith("filterStatus", "Accepted");

    await userEvent.click(screen.getByRole("button", { name: "Shared sort and filter" }));
    await userEvent.click(screen.getByRole("button", { name: "Ascending" }));
    expect(onSort).toHaveBeenCalledWith("createdAt", "ASC");
    expect(screen.getByRole("columnheader", { name: "Actions" })).toBeInTheDocument();
  });
});
