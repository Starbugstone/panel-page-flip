import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { RedeemCodePanel } from "./RedeemCodePanel";
import { SharingIdentityPanel } from "./SharingIdentityPanel";

describe("mobile sharing controls", () => {
  it("separates the account code from its actions on a phone", () => {
    render(
      <SharingIdentityPanel
        identity={{ username: "Reader", userCode: "U-AAAA-BBBB-CCCC" }}
        loadFailed={false}
        copied={false}
        isRotating={false}
        onCopy={vi.fn()}
        onRotate={vi.fn()}
      />,
    );

    const code = screen.getByLabelText("Your user code");
    expect(code.parentElement).toHaveClass("grid", "grid-cols-2", "sm:flex");
    expect(code).toHaveClass("col-span-2", "min-w-0", "break-all", "sm:flex-1");
    expect(screen.getByRole("button", { name: "Copy your user code" })).toHaveClass("w-full", "sm:w-auto");
  });

  it("stacks the redeem field and action on a phone", () => {
    render(
      <RedeemCodePanel
        value=""
        onChange={vi.fn()}
        isRedeeming={false}
        error={null}
        onRedeem={vi.fn()}
      />,
    );

    const field = screen.getByLabelText("Sharing code");
    expect(field.parentElement.parentElement).toHaveClass("flex-col", "sm:flex-row", "sm:items-end");
    expect(screen.getByRole("button", { name: "Redeem" })).toHaveClass("w-full", "sm:w-auto");
  });
});
