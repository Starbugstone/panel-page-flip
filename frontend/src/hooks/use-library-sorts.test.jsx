import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useLibrarySorts } from "./use-library-sorts";

const setup = (initialView = "all") => renderHook(
  ({ activeView }) => useLibrarySorts(activeView),
  { initialProps: { activeView: initialView } }
);

describe("useLibrarySorts", () => {
  it("starts the library alphabetical and the reading queue by recency", () => {
    const { result, rerender } = setup("all");
    expect(result.current.sort).toBe("title-asc");

    rerender({ activeView: "reading" });
    expect(result.current.sort).toBe("last-read-desc");
  });

  it("shares one choice across everything that is not the reading queue", () => {
    const { result, rerender } = setup("all");
    act(() => result.current.setSort("uploaded-desc"));

    rerender({ activeView: "unread" });
    expect(result.current.sort).toBe("uploaded-desc");
  });

  it("keeps each view's choice when the other view's changes", () => {
    const { result, rerender } = setup("reading");
    act(() => result.current.setSort("title-desc"));

    rerender({ activeView: "all" });
    expect(result.current.sort).toBe("title-asc");
    act(() => result.current.setSort("updated-desc"));

    rerender({ activeView: "reading" });
    expect(result.current.sort).toBe("title-desc");
  });
});
