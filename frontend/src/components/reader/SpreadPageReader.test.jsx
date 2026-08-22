import { render } from "@testing-library/react";
import { createRef } from "react";
import { describe, expect, it } from "vitest";

import { SpreadPageReader } from "./SpreadPageReader";

describe("spread sizing", () => {
  it("lets original-size pages establish the scrollable spread width without overlapping", () => {
    render(
      <SpreadPageReader
        containerRef={createRef()}
        contentRef={createRef()}
        fit="original"
        pages={[
          { pageIndex: 1, image: { src: "/pages/2" } },
          { pageIndex: 2, image: { src: "/pages/3" } },
        ]}
      />
    );

    const spread = document.querySelector('[data-reader-mode="double"]');
    const content = spread.firstElementChild;
    expect(content).toHaveClass("w-max", "max-w-none");
    [...content.children].forEach((slot) => expect(slot).toHaveClass("flex-none"));
  });
});
