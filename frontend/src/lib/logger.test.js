import { afterEach, describe, expect, it, vi } from "vitest";
import { logger } from "./logger";

describe("logger", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("always writes errors", () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => {});

    logger.error("boom");

    expect(error).toHaveBeenCalledWith("boom");
  });
});
