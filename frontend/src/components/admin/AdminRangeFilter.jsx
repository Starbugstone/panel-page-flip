import { formatBytes } from "@/lib/format";

const formatNumber = (value) => Number(value).toLocaleString();

function rangeFromValue(value, maximum, step) {
  const match = /^(\d+)\.\.(\d+)$/.exec(String(value ?? ""));
  if (!match) return { minimum: 0, maximum };

  return {
    minimum: Math.min(maximum, Math.max(0, Math.floor(Number(match[1]) / step) * step)),
    maximum: Math.min(maximum, Math.max(0, Math.ceil(Number(match[2]) / step) * step)),
  };
}

/** One track with independently accessible minimum and maximum handles. */
export function AdminRangeFilter({ label, value, max = 0, step = 1, format = "number", onChange }) {
  const maximum = Math.max(0, Math.ceil((Number(max) || 0) / step) * step);
  const range = rangeFromValue(value, maximum, step);
  const denominator = maximum || 1;
  const minimumPercent = (range.minimum / denominator) * 100;
  const maximumPercent = maximum === 0 ? 100 : (range.maximum / maximum) * 100;
  const formatValue = format === "bytes" ? formatBytes : formatNumber;
  const setMinimum = (next) => onChange(`${Math.min(Number(next), range.maximum)}..${range.maximum}`);
  const setMaximum = (next) => onChange(`${range.minimum}..${Math.max(Number(next), range.minimum)}`);

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">
        {formatValue(range.minimum)} to {formatValue(range.maximum)}
      </p>
      <div className="relative h-5">
        <div className="absolute inset-x-0 top-2 h-1 rounded-full bg-muted" aria-hidden="true" />
        <div
          className="absolute top-2 h-1 rounded-full bg-primary"
          style={{ left: `${minimumPercent}%`, right: `${100 - maximumPercent}%` }}
          aria-hidden="true"
        />
        <input
          className="admin-range-input absolute inset-x-0 top-0 w-full"
          type="range"
          min="0"
          max={maximum}
          step={step}
          value={range.minimum}
          disabled={maximum === 0}
          onChange={(event) => setMinimum(event.target.value)}
          aria-label={`Minimum ${label.toLowerCase()}`}
          aria-valuetext={formatValue(range.minimum)}
        />
        <input
          className="admin-range-input absolute inset-x-0 top-0 w-full"
          type="range"
          min="0"
          max={maximum}
          step={step}
          value={range.maximum}
          disabled={maximum === 0}
          onChange={(event) => setMaximum(event.target.value)}
          aria-label={`Maximum ${label.toLowerCase()}`}
          aria-valuetext={formatValue(range.maximum)}
        />
      </div>
    </div>
  );
}
