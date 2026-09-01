import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import {
  AdminDateRangeCalendar,
  AdminDateRangePopover,
} from "./AdminDateRangePicker";

function CalendarHarness({ initialValue = "" }) {
  const [value, setValue] = useState(initialValue);
  return (
    <>
      <AdminDateRangeCalendar value={value} onChange={setValue} initialMonth="2026-08-01" />
      <output>{value}</output>
    </>
  );
}

function PopoverHarness() {
  const [value, setValue] = useState("2026-08-01..2026-08-31");
  return (
    <>
      <AdminDateRangePopover label="Submitted" value={value} onChange={setValue} />
      <output>{value}</output>
    </>
  );
}

describe("AdminDateRangePicker", () => {
  it("selects and normalizes both inclusive calendar boundaries", async () => {
    const user = userEvent.setup();
    render(<CalendarHarness />);

    await user.click(screen.getByRole("button", { name: "August 20, 2026" }));
    await user.click(screen.getByRole("button", { name: "August 10, 2026" }));

    expect(screen.getByText("2026-08-10..2026-08-20", { selector: "output" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "August 10, 2026" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "August 20, 2026" })).toHaveAttribute("aria-pressed", "true");
  });

  it("keeps a toolbar range as a draft until Apply is selected", async () => {
    const user = userEvent.setup();
    render(<PopoverHarness />);

    await user.click(screen.getByRole("button", { name: /Submitted date range/i }));
    await user.click(screen.getByRole("button", { name: "Today" }));
    expect(screen.getByText("2026-08-01..2026-08-31", { selector: "output" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Apply range" }));
    expect(screen.queryByText("2026-08-01..2026-08-31", { selector: "output" })).not.toBeInTheDocument();
    expect(screen.getByText(/^\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2}$/, { selector: "output" }))
      .toBeInTheDocument();
  });

  it("labels exact, open, and empty ranges on the closed control", () => {
    const { rerender } = render(<AdminDateRangePopover label="Created" value="2026-08-14" onChange={() => {}} />);
    expect(screen.getByRole("button", { name: "Created date range: Aug 14, 2026" })).toBeInTheDocument();

    rerender(<AdminDateRangePopover label="Created" value="2026-08-14.." onChange={() => {}} />);
    expect(screen.getByRole("button", { name: "Created date range: From Aug 14, 2026" })).toBeInTheDocument();

    rerender(<AdminDateRangePopover label="Created" value="..2026-08-31" onChange={() => {}} />);
    expect(screen.getByRole("button", { name: "Created date range: Through Aug 31, 2026" })).toBeInTheDocument();

    rerender(<AdminDateRangePopover label="Created" value="never" onChange={() => {}} />);
    expect(screen.getByRole("button", { name: "Created date range: No date" })).toBeInTheDocument();
  });
});
