import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { UserStorageUsage } from "./UserStorageUsage";

const GIB = 1024 ** 3;

describe("UserStorageUsage", () => {
  it("shows an account that has used nothing", () => {
    render(<UserStorageUsage usedBytes={0} quotaBytes={10 * GIB} />);

    expect(screen.getByText("0 B")).toBeInTheDocument();
    expect(screen.getByText("0.0%")).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "0");
  });

  it("puts used, quota and percentage in the accessible label, not only in the bar", () => {
    render(<UserStorageUsage usedBytes={8.3 * GIB} quotaBytes={10 * GIB} />);

    expect(screen.getByRole("progressbar")).toHaveAccessibleName(
      "Storage used: 8.30 GiB of 10.00 GiB, 83.0%."
    );
  });

  it("clamps the bar past the limit but never the figure", () => {
    render(<UserStorageUsage usedBytes={11.2 * GIB} quotaBytes={10 * GIB} />);

    expect(screen.getByText("112.0%")).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "100");
    expect(screen.getByRole("progressbar")).toHaveAccessibleName(/112\.0%/);
  });

  it("says the total may be understated when sizes are missing", () => {
    render(<UserStorageUsage usedBytes={6 * GIB} quotaBytes={10 * GIB} unmeasuredComicCount={2} />);

    expect(screen.getByRole("progressbar")).toHaveAccessibleName(
      /Measured storage used: 6\.00 GiB of 10\.00 GiB.*2 comics have no stored file-size metadata/
    );
  });

  it("counts a single missing size in the singular", () => {
    render(<UserStorageUsage usedBytes={1024} quotaBytes={10 * GIB} unmeasuredComicCount={1} />);

    expect(screen.getByRole("progressbar")).toHaveAccessibleName(/1 comic has no stored file-size metadata/);
  });

  it("reveals the exact byte counts on hover", async () => {
    const user = userEvent.setup();
    render(<UserStorageUsage usedBytes={8912345678} quotaBytes={10737418240} />);

    await user.hover(screen.getByRole("progressbar"));

    expect(await screen.findByText("8,912,345,678 / 10,737,418,240 bytes")).toBeInTheDocument();
  });

  it("reports no percentage when the quota is unlimited", () => {
    render(<UserStorageUsage usedBytes={5 * GIB} quotaBytes={0} />);

    expect(screen.getByText("Unlimited")).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAccessibleName("Storage used: 5.00 GiB. Unlimited storage quota.");
  });
});
