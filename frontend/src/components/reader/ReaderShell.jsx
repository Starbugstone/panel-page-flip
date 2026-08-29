import { useRef } from "react";

/**
 * The reader's outermost element: the box everything is measured against, and
 * the place the whole-surface pointer rules live.
 *
 * The data attributes are the reader's state made visible to CSS, which is what
 * lets the stylesheet reserve room for the controls and fade the chrome without
 * any of that logic being duplicated in JavaScript.
 */
export function ReaderShell({
  effectiveMode, isFullscreen, isChromeHidden, touchCapable, controlsHeight,
  onControlFocusChange, onReveal, children,
}) {
  const rootRef = useRef(null);

  return (
    <div
      ref={rootRef}
      className="reader-root relative flex flex-col items-center overflow-hidden bg-background"
      data-touch-capable={touchCapable ? "true" : "false"}
      data-effective-reader-mode={effectiveMode}
      data-fullscreen={isFullscreen ? "true" : "false"}
      data-reader-chrome={isChromeHidden ? "hidden" : "visible"}
      style={controlsHeight ? { "--reader-controls-height": `${controlsHeight}px` } : undefined}
      onFocus={() => onControlFocusChange(true)}
      onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) onControlFocusChange(false); }}
      onPointerDownCapture={(event) => {
        // A control that has been pressed keeps focus, and focus pins the chrome
        // open. Pressing the page is a request for the page, not for the button.
        if (!event.target.closest?.("[data-page-fit], [data-reader-mode]")) return;
        const active = document.activeElement;
        if (active instanceof HTMLElement && rootRef.current?.contains(active)) active.blur();
      }}
      onPointerMove={(event) => { if (event.pointerType === "mouse") onReveal(); }}
    >
      {children}
    </div>
  );
}
