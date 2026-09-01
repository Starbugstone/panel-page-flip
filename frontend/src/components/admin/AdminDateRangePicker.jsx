import { useState } from "react";
import { CalendarRange, ChevronDown, ChevronLeft, ChevronRight, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  ADMIN_EMPTY_DATE,
  localCalendarDate,
  parseAdminDateRange,
  serializeAdminDateRange,
} from "@/lib/admin-table-filters";
import { cn } from "@/lib/utils";

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const dayFormatter = new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" });
const fullDayFormatter = new Intl.DateTimeFormat("en-US", { month: "long", day: "numeric", year: "numeric" });
const monthFormatter = new Intl.DateTimeFormat("en-US", { month: "long", year: "numeric" });

/**
 * A visible, application-styled calendar. It deliberately does not use native
 * date inputs: their picker indicator can disappear into a dark theme and the
 * closed control otherwise looks indistinguishable from an ordinary text box.
 */
export function AdminDateRangeCalendar({ value, onChange, emptyDateLabel, initialMonth, className }) {
  const range = parseAdminDateRange(value);
  const [selectionTarget, setSelectionTarget] = useState(() => range.from && !range.to ? "to" : "from");
  const [visibleMonth, setVisibleMonth] = useState(() => monthFor(initialMonth || range.from || range.to));
  const monthStart = new Date(visibleMonth.year, visibleMonth.month, 1, 12);
  const firstWeekday = monthStart.getDay();
  const daysInMonth = new Date(visibleMonth.year, visibleMonth.month + 1, 0).getDate();
  const today = localCalendarDate(new Date());

  const setRange = ({ from = range.from, to = range.to, empty = false }) => {
    onChange(serializeAdminDateRange({ from, to, empty }));
  };

  const chooseDay = (day) => {
    const picked = localCalendarDate(new Date(visibleMonth.year, visibleMonth.month, day, 12));

    if (selectionTarget === "from") {
      setRange({ from: picked, to: range.to && range.to >= picked ? range.to : "" });
      setSelectionTarget("to");
      return;
    }

    if (!range.from) {
      setRange({ from: "", to: picked });
      setSelectionTarget("from");
      return;
    }

    if (picked < range.from) {
      setRange({ from: picked, to: range.from });
    } else {
      setRange({ from: range.from, to: picked });
    }
    setSelectionTarget("from");
  };

  const chooseQuickRange = (kind) => {
    const end = new Date();
    const start = new Date(end);
    if (kind === "week") start.setDate(start.getDate() - 6);
    if (kind === "month") start.setDate(1);
    const from = localCalendarDate(start);
    const to = localCalendarDate(end);
    setRange({ from, to });
    setVisibleMonth(monthFor(from));
    setSelectionTarget("from");
  };

  const moveMonth = (offset) => setVisibleMonth((current) => monthFor(
    new Date(current.year, current.month + offset, 1, 12)
  ));

  return (
    <div className={cn("space-y-3", className)}>
      <div className="grid grid-cols-[1fr_auto_1fr] items-stretch gap-2">
        <RangeEndpoint
          label="From"
          value={range.from}
          active={!range.empty && selectionTarget === "from"}
          onClick={() => setSelectionTarget("from")}
        />
        <span className="self-center text-muted-foreground" aria-hidden="true">→</span>
        <RangeEndpoint
          label="Through"
          value={range.to}
          active={!range.empty && selectionTarget === "to"}
          onClick={() => setSelectionTarget("to")}
        />
      </div>

      <div className="rounded-lg border bg-background p-2">
        <div className="mb-2 grid grid-cols-[2.25rem_1fr_2.25rem] items-center">
          <Button type="button" variant="ghost" size="icon" className="h-9 w-9" aria-label="Previous month" onClick={() => moveMonth(-1)}>
            <ChevronLeft />
          </Button>
          <p className="text-center text-sm font-semibold" aria-live="polite">{monthFormatter.format(monthStart)}</p>
          <Button type="button" variant="ghost" size="icon" className="h-9 w-9" aria-label="Next month" onClick={() => moveMonth(1)}>
            <ChevronRight />
          </Button>
        </div>

        <div className="grid grid-cols-7 gap-0.5 text-center">
          {WEEKDAYS.map((weekday) => (
            <span key={weekday} className="py-1 text-[0.7rem] font-medium text-muted-foreground" aria-hidden="true">{weekday}</span>
          ))}
          {Array.from({ length: 42 }, (_, index) => {
            const day = index - firstWeekday + 1;
            if (day < 1 || day > daysInMonth) return <span key={`blank-${index}`} aria-hidden="true" />;

            const key = localCalendarDate(new Date(visibleMonth.year, visibleMonth.month, day, 12));
            const isEdge = key === range.from || key === range.to;
            const isInside = Boolean(range.from && range.to && key > range.from && key < range.to);
            return (
              <button
                key={key}
                type="button"
                aria-label={fullDayFormatter.format(new Date(`${key}T12:00:00`))}
                aria-pressed={isEdge}
                onClick={() => chooseDay(day)}
                className={cn(
                  "mx-auto flex h-9 w-9 items-center justify-center rounded-md text-sm outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1",
                  isInside && "rounded-none bg-accent/80",
                  isEdge && "bg-primary font-semibold text-primary-foreground hover:bg-primary/90",
                  key === today && !isEdge && "border border-primary font-semibold",
                )}
              >
                {day}
              </button>
            );
          })}
        </div>
      </div>

      <div className="flex flex-wrap gap-1">
        <Button type="button" size="sm" variant="outline" className="h-8 px-2 text-xs" onClick={() => chooseQuickRange("today")}>Today</Button>
        <Button type="button" size="sm" variant="outline" className="h-8 px-2 text-xs" onClick={() => chooseQuickRange("week")}>Past 7 days</Button>
        <Button type="button" size="sm" variant="outline" className="h-8 px-2 text-xs" onClick={() => chooseQuickRange("month")}>This month</Button>
        {emptyDateLabel && (
          <Button
            type="button"
            size="sm"
            variant={String(value).toLocaleLowerCase() === ADMIN_EMPTY_DATE ? "default" : "outline"}
            className="h-8 px-2 text-xs"
            onClick={() => setRange({ from: "", to: "", empty: true })}
          >
            {emptyDateLabel}
          </Button>
        )}
      </div>
      <p className="text-xs text-muted-foreground">Select either boundary, then choose a day. Both selected dates are included.</p>
    </div>
  );
}

export function AdminDateRangePopover({ label, value, onChange, className, align = "end" }) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(value || "");

  return (
    <Popover open={open} onOpenChange={(nextOpen) => {
      if (nextOpen) setDraft(value || "");
      setOpen(nextOpen);
    }}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          className={cn("h-10 min-w-[15rem] justify-start px-3", className)}
          aria-label={`${label} date range: ${formatAdminDateRange(value)}`}
        >
          <CalendarRange className="text-muted-foreground" />
          <span className="font-medium">{label}</span>
          <span className="min-w-0 flex-1 truncate text-left font-normal text-muted-foreground">{formatAdminDateRange(value)}</span>
          <ChevronDown className="text-muted-foreground" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align={align}
        collisionPadding={8}
        className="max-h-[var(--radix-popover-content-available-height)] w-[min(22rem,calc(100vw-1rem))] space-y-3 overflow-y-auto"
      >
        <div>
          <p className="text-sm font-semibold">{label} date range</p>
          <p className="text-xs text-muted-foreground">Choose a start, an end, or leave either side open.</p>
        </div>
        <AdminDateRangeCalendar value={draft} onChange={setDraft} />
        <div className="flex justify-end gap-2 border-t pt-3">
          {(value || draft) && (
            <Button type="button" size="sm" variant="ghost" onClick={() => {
              setDraft("");
              onChange("");
              setOpen(false);
            }}>
              <X /> Clear
            </Button>
          )}
          <Button type="button" size="sm" onClick={() => { onChange(draft); setOpen(false); }}>Apply range</Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}

function formatAdminDateRange(value, emptyLabel = "Any date") {
  const range = parseAdminDateRange(value);
  if (range.empty) return "No date";
  if (!range.from && !range.to) return emptyLabel;
  if (range.from && range.from === range.to) return formatDay(range.from);
  if (range.from && range.to) return `${formatDay(range.from)} – ${formatDay(range.to)}`;
  return range.from ? `From ${formatDay(range.from)}` : `Through ${formatDay(range.to)}`;
}

function RangeEndpoint({ label, value, active, onClick }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={cn(
        "min-w-0 rounded-md border px-2 py-2 text-left outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring",
        active && "border-primary bg-primary/10 ring-1 ring-primary",
      )}
    >
      <span className="block text-[0.65rem] font-semibold uppercase tracking-wide text-muted-foreground">{label}</span>
      <span className="block truncate text-sm font-medium">{value ? formatDay(value) : `Choose ${label.toLowerCase()}`}</span>
    </button>
  );
}

function monthFor(value) {
  const date = value instanceof Date
    ? value
    : typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value)
      ? new Date(`${value}T12:00:00`)
      : new Date();
  return { year: date.getFullYear(), month: date.getMonth() };
}

function formatDay(value) {
  return dayFormatter.format(new Date(`${value}T12:00:00`));
}
