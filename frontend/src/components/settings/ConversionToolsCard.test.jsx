import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CONVERSION_TOOLS, CONVERSION_TOOLS_VERSION } from "@/lib/conversion-tools";
import { ConversionToolsCard } from "./ConversionToolsCard";

describe("ConversionToolsCard", () => {
  it("publishes every platform download with its checksum", () => {
    render(<ConversionToolsCard />);

    for (const tool of CONVERSION_TOOLS) {
      const link = screen.getByRole("link", { name: tool.label });
      expect(link).toHaveAttribute("href", tool.href);
      expect(link).toHaveAttribute("download", tool.fileName);
      expect(screen.getByText(new RegExp(`${tool.fileName}: ${tool.sha256}`))).toBeInTheDocument();
    }
    expect(screen.getByText(new RegExp(`Version ${CONVERSION_TOOLS_VERSION}`))).toBeInTheDocument();
  });
});
