import { forwardRef, useId, useMemo, useRef, useState } from "react";
import { ArrowDownAZ, ArrowUpAZ, CalendarRange, ListFilter, X } from "lucide-react";

import { AdminDateRangeCalendar } from "@/components/admin/AdminDateRangePicker";
import { AdminRangeFilter } from "@/components/admin/AdminRangeFilter";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { parseAdminDateRange } from "@/lib/admin-table-filters";
import { cn } from "@/lib/utils";

const EMPTY_VALUES = [];

/** A table heading with explicit sort and filter controls. */
export function AdminColumnHeader({
  label,
  sortField,
  filterField,
  sort,
  direction,
  filterValue = "",
  onSort,
  onFilter,
  filterPlaceholder,
  filterType = "text",
  filterSuggestions = EMPTY_VALUES,
  filterOptions = EMPTY_VALUES,
  emptyDateLabel,
  filterMax,
  filterStep,
  filterFormat,
  className,
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(filterValue);
  const activeSort = Boolean(sortField && sort === sortField);
  const activeFilter = Boolean(filterValue);

  const close = () => setOpen(false);
  const chooseValue = (value) => {
    setDraft(value);
    onFilter(filterField, value);
    close();
  };

  return (
    <div className={cn("flex items-center gap-1", className)}>
      <span>{label}</span>
      <Popover open={open} onOpenChange={(nextOpen) => {
        if (nextOpen) setDraft(filterValue);
        setOpen(nextOpen);
      }}>
        <PopoverTrigger asChild>
          <ColumnTrigger
            label={label}
            activeSort={activeSort}
            activeFilter={activeFilter}
            direction={direction}
            filterType={filterType}
          />
        </PopoverTrigger>
        <PopoverContent
          align="start"
          collisionPadding={8}
          className={cn(
            "space-y-3",
            filterType === "date" && "max-h-[var(--radix-popover-content-available-height)] w-[min(20rem,calc(100vw-1rem))] overflow-y-auto"
          )}
        >
          <p className="text-sm font-medium">{label}</p>
          {sortField && (
            <SortControls
              sortField={sortField}
              activeSort={activeSort}
              direction={direction}
              onSort={onSort}
              onDone={close}
            />
          )}
          {filterField && (
            <FilterControls
              label={label}
              field={filterField}
              type={filterType}
              draft={draft}
              active={activeFilter}
              suggestions={filterSuggestions}
              options={filterOptions}
              placeholder={filterPlaceholder}
              emptyDateLabel={emptyDateLabel}
              max={filterMax}
              step={filterStep}
              format={filterFormat}
              autoFocus={!sortField}
              onDraftChange={setDraft}
              onChoose={chooseValue}
              onApply={() => chooseValue(draft)}
              onClear={() => chooseValue("")}
            />
          )}
        </PopoverContent>
      </Popover>
    </div>
  );
}

const ColumnTrigger = forwardRef(function ColumnTrigger(
  { label, activeSort, activeFilter, direction, filterType, ...triggerProps },
  ref
) {
  return (
    <Button
      {...triggerProps}
      ref={ref}
      type="button"
      variant="ghost"
      size="icon"
      className={cn(
        "relative h-7 w-7 text-muted-foreground",
        (activeSort || activeFilter) && "bg-accent text-foreground"
      )}
      aria-label={`${label} sort and filter`}
      aria-pressed={activeSort || activeFilter}
    >
      {activeSort
        ? (direction === "ASC" ? <ArrowUpAZ /> : <ArrowDownAZ />)
        : (filterType === "date" ? <CalendarRange /> : <ListFilter />)}
      {activeFilter && (
        <span className="absolute right-0.5 top-0.5 h-1.5 w-1.5 rounded-full bg-primary" aria-hidden="true" />
      )}
    </Button>
  );
});

function SortControls({ sortField, activeSort, direction, onSort, onDone }) {
  const sortBy = (nextDirection) => {
    onSort(sortField, nextDirection);
    onDone();
  };

  return (
    <div className="grid grid-cols-2 gap-2">
      <Button
        type="button"
        size="sm"
        variant={activeSort && direction === "ASC" ? "default" : "outline"}
        onClick={() => sortBy("ASC")}
      >
        <ArrowUpAZ /> Ascending
      </Button>
      <Button
        type="button"
        size="sm"
        variant={activeSort && direction === "DESC" ? "default" : "outline"}
        onClick={() => sortBy("DESC")}
      >
        <ArrowDownAZ /> Descending
      </Button>
    </div>
  );
}

function FilterControls(props) {
  if (props.type === "select") {
    return (
      <select
        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
        value={props.draft}
        onChange={(event) => props.onChoose(event.target.value)}
        aria-label={`Filter ${props.label}`}
      >
        <option value="">Any value</option>
        {props.options.map((option) => <option key={option} value={option}>{option}</option>)}
      </select>
    );
  }

  const invalid = invalidDateRange(props.type, props.draft);
  return (
    <form className="space-y-2" onSubmit={(event) => {
      event.preventDefault();
      if (!invalid) props.onApply();
    }}>
      <FilterEditor {...props} />
      <div className="flex justify-end gap-2">
        {(props.active || props.draft) && (
          <Button type="button" size="sm" variant="ghost" onClick={props.onClear}>
            <X /> Clear
          </Button>
        )}
        <Button type="submit" size="sm" disabled={invalid}>
          {props.type === "date" || props.type === "range" ? "Apply range" : "Apply filter"}
        </Button>
      </div>
    </form>
  );
}

function FilterEditor(props) {
  if (props.type === "date") {
    return <AdminDateRangeCalendar value={props.draft} onChange={props.onDraftChange} emptyDateLabel={props.emptyDateLabel} />;
  }
  if (props.type === "range") {
    return (
      <AdminRangeFilter
        label={props.label}
        value={props.draft}
        max={props.max}
        step={props.step}
        format={props.format}
        onChange={props.onDraftChange}
      />
    );
  }

  return <TextFilter {...props} />;
}

function TextFilter({ label, draft, suggestions, placeholder, autoFocus, onDraftChange, onChoose }) {
  const suggestionsRef = useRef(null);
  const suggestionsId = useId();
  const matches = useMemo(() => matchingSuggestions(draft, suggestions), [draft, suggestions]);

  return (
    <>
      <Input
        type="search"
        value={draft}
        onChange={(event) => onDraftChange(event.target.value)}
        onKeyDown={(event) => {
          if (event.key !== "ArrowDown" || matches.length === 0) return;
          event.preventDefault();
          suggestionsRef.current?.querySelector("button")?.focus();
        }}
        placeholder={placeholder || `Filter ${label.toLowerCase()}…`}
        aria-label={`Filter ${label}`}
        aria-autocomplete={suggestions.length > 0 ? "list" : undefined}
        aria-controls={matches.length > 0 ? suggestionsId : undefined}
        autoFocus={autoFocus}
      />
      {matches.length > 0 && (
        <div
          ref={suggestionsRef}
          id={suggestionsId}
          role="listbox"
          aria-label={`${label} suggestions`}
          className="max-h-52 overflow-y-auto rounded-md border bg-popover p-1 shadow-sm"
        >
          {matches.map((suggestion) => (
            <button
              key={suggestion}
              type="button"
              role="option"
              aria-selected={suggestion === draft}
              className="flex w-full min-w-0 items-center break-words rounded-sm px-2 py-2 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent"
              onClick={() => onChoose(suggestion)}
            >
              {suggestion}
            </button>
          ))}
        </div>
      )}
    </>
  );
}

function invalidDateRange(type, draft) {
  if (type !== "date") return false;
  const range = parseAdminDateRange(draft);

  return Boolean(range && (!range.valid || (range.from && range.to && range.from > range.to)));
}

function matchingSuggestions(draft, suggestions) {
  const query = String(draft ?? "").trim().toLocaleLowerCase();
  if (!query) return [];

  return [...new Map(suggestions.map((value) => {
    const text = String(value ?? "").trim();
    return [text.toLocaleLowerCase(), text];
  })).values()]
    .filter((value) => value && value.toLocaleLowerCase().includes(query))
    .sort((left, right) => {
      const leftStarts = left.toLocaleLowerCase().startsWith(query);
      const rightStarts = right.toLocaleLowerCase().startsWith(query);
      if (leftStarts !== rightStarts) return leftStarts ? -1 : 1;

      return left.localeCompare(right, undefined, { numeric: true, sensitivity: "base" });
    })
    .slice(0, 6);
}
