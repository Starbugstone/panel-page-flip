import { ChevronLeft, ChevronRight } from "lucide-react";

function TurnZone({ side, label, disabled, onTurn }) {
  const Icon = side === "left" ? ChevronLeft : ChevronRight;

  return (
    <button
      type="button"
      className={`reader-turn-zone reader-turn-zone-${side} group flex h-full w-full items-center ${side === "left" ? "justify-end" : "justify-start"}`}
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={(event) => {
        event.stopPropagation();
        onTurn();
      }}
    >
      <span className={`reader-turn-zone-indicator flex h-16 w-10 items-center justify-center border bg-background/75 shadow-md backdrop-blur-sm ${side === "left" ? "rounded-r-full border-l-0" : "rounded-l-full border-r-0"}`}>
        <Icon className="h-6 w-6" aria-hidden="true" />
      </span>
    </button>
  );
}

/**
 * Page navigation in real gutters beside the reader surface. Because the
 * buttons are siblings of the artwork rather than an overlay, no fit or zoom
 * can put a control over a panel.
 */
export function ReaderPageTurnZones({
  leftLabel,
  rightLabel,
  onLeft,
  onRight,
  leftDisabled = false,
  rightDisabled = false,
  children,
}) {
  return (
    <div
      className="reader-paged-layout grid h-full min-h-0 w-full items-stretch"
      data-reader-turn-zones="true"
    >
      <TurnZone side="left" label={leftLabel} onTurn={onLeft} disabled={leftDisabled} />
      <div className="min-h-0 min-w-0">{children}</div>
      <TurnZone side="right" label={rightLabel} onTurn={onRight} disabled={rightDisabled} />
    </div>
  );
}
