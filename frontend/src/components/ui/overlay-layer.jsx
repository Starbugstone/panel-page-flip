import * as React from "react";

import { overlayLayerClass } from "@/lib/overlay-layers";

/**
 * How many modals enclose the current subtree.
 *
 * React context crosses portals, so a `Select` rendered from inside a
 * `DialogContent` still reads the depth the dialog published even though its
 * DOM node is a sibling of the dialog on `document.body`. That is what lets a
 * nested popover pick a layer above the modal it belongs to.
 */
const OverlayDepthContext = React.createContext(0);

export function useOverlayDepth() {
  return React.useContext(OverlayDepthContext);
}

/** The `z-*` class for a dropdown, popover, select, or tooltip. */
export function usePopoverLayerClass() {
  return overlayLayerClass("popover", useOverlayDepth());
}

/** The `z-*` classes for a modal's overlay and content. */
export function useModalLayerClasses() {
  const depth = useOverlayDepth();

  return React.useMemo(
    () => ({
      overlay: overlayLayerClass("modalOverlay", depth),
      content: overlayLayerClass("modalContent", depth),
    }),
    [depth]
  );
}

/**
 * Wraps a modal's own children so everything they open lands above the modal.
 * The modal's overlay and content stay at the outer depth — only what they
 * contain moves up a level.
 */
export function ModalLayerBoundary({ children }) {
  const depth = useOverlayDepth();
  const nested = React.useMemo(() => depth + 1, [depth]);

  return <OverlayDepthContext.Provider value={nested}>{children}</OverlayDepthContext.Provider>;
}
