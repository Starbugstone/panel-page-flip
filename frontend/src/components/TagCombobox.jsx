import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { Globe2, Loader2, Plus } from "lucide-react";

import { Input } from "@/components/ui/input";
import { useTags } from "@/hooks/use-tags.jsx";
import { cn } from "@/lib/utils.js";
import { logger } from "@/lib/logger";
import {
  describeTagSubmission,
  filterTags,
  isGlobalTag,
  normalizeTagName,
  tagKeyOf,
} from "@/lib/tag-suggestions.js";

/**
 * A text field that offers the tags the current user already has.
 *
 * The bulk toolbar in the table view, the comic edit dialog and anything else
 * that adds a tag share this control, so they cannot drift into offering
 * different suggestions — the bulk toolbar used to be a plain text box, which
 * is how spelling variants of existing tags got created (#65).
 *
 * Submission is reported through `onSubmit(name, meta)`, where `name` is the
 * canonical spelling: picking "sci fi" out of the list submits the stored
 * "Sci Fi". `applied` lists the tags already on whatever is being edited, so
 * they can be shown as unavailable rather than silently doing nothing.
 */
export function TagCombobox({
  id,
  value,
  onChange,
  onSubmit,
  applied = [],
  allowCreate = true,
  disabled = false,
  placeholder = "Add a tag",
  label = "Add a tag",
  className,
  inputClassName,
  autoFocus = false,
}) {
  const listId = useId();
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [remoteTags, setRemoteTags] = useState([]);
  const [isSearching, setIsSearching] = useState(false);
  const containerRef = useRef(null);
  const inputRef = useRef(null);
  const { tags: cachedTags, searchTags, isAdminContext } = useTags();
  const adminContext = isAdminContext();

  // The cache covers the common case; the search endpoint fills in tags that a
  // large library has not loaded locally. Merged so a suggestion never
  // disappears just because the request is still in flight.
  const availableTags = useMemo(() => {
    const byKey = new Map();
    for (const tag of [...(cachedTags || []), ...remoteTags]) {
      const key = tagKeyOf(tag);
      if (key && !byKey.has(key)) byKey.set(key, tag);
    }

    return [...byKey.values()];
  }, [cachedTags, remoteTags]);

  const suggestions = useMemo(() => filterTags(availableTags, value), [availableTags, value]);
  const submission = useMemo(
    () => describeTagSubmission(availableTags, value, applied),
    [applied, availableTags, value]
  );

  const appliedKeys = useMemo(() => new Set((applied || []).map(tagKeyOf)), [applied]);
  const canCreate = allowCreate && submission.status === "new";

  useEffect(() => {
    const query = normalizeTagName(value);
    if (query.length < 2) return undefined;

    const timeoutId = setTimeout(() => {
      setIsSearching(true);
      searchTags(query, adminContext)
        .then((results) => setRemoteTags(results || []))
        .catch((error) => logger.error("Error searching tags:", error))
        .finally(() => setIsSearching(false));
    }, 300);

    return () => clearTimeout(timeoutId);
  }, [adminContext, searchTags, value]);

  // Reset the highlight whenever the list underneath it changes, so Enter never
  // picks a suggestion the user is no longer looking at.
  useEffect(() => { setActiveIndex(-1); }, [value]);

  useEffect(() => {
    if (disabled) setIsOpen(false);
  }, [disabled]);

  useEffect(() => {
    const handlePointerDown = (event) => {
      if (!containerRef.current?.contains(event.target)) setIsOpen(false);
    };

    document.addEventListener("mousedown", handlePointerDown);
    return () => document.removeEventListener("mousedown", handlePointerDown);
  }, []);

  const submit = useCallback((name) => {
    const result = describeTagSubmission(availableTags, name, applied);
    if (result.status === "empty" || result.status === "duplicate") return;
    if (result.status === "new" && !allowCreate) return;

    setIsOpen(false);
    setActiveIndex(-1);
    onSubmit(result.name, result);
    // Adding tags is repetitive work; keep the caret where the next one goes.
    inputRef.current?.focus();
  }, [allowCreate, applied, availableTags, onSubmit]);

  const handleKeyDown = (event) => {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!isOpen) setIsOpen(true);
      if (suggestions.length === 0) return;

      const step = event.key === "ArrowDown" ? 1 : -1;
      setActiveIndex((current) => {
        const next = current + step;
        if (next < 0) return suggestions.length - 1;
        if (next >= suggestions.length) return 0;
        return next;
      });
      return;
    }

    if (event.key === "Enter") {
      event.preventDefault();
      const highlighted = isOpen && activeIndex >= 0 ? suggestions[activeIndex] : null;
      submit(highlighted ?? value);
      return;
    }

    if (event.key === "Escape" && isOpen) {
      // Only swallow Escape while the list is open, so it still closes the
      // dialog this control may be sitting in.
      event.preventDefault();
      event.stopPropagation();
      setIsOpen(false);
      setActiveIndex(-1);
    }
  };

  const activeOptionId = activeIndex >= 0 ? `${listId}-option-${activeIndex}` : undefined;

  return (
    <div ref={containerRef} className={cn("relative", className)}>
      <Input
        ref={inputRef}
        id={id}
        value={value}
        onChange={(event) => {
          onChange(event.target.value);
          if (!disabled) setIsOpen(true);
        }}
        onFocus={() => !disabled && setIsOpen(true)}
        onKeyDown={handleKeyDown}
        disabled={disabled}
        placeholder={placeholder}
        aria-label={label}
        maxLength={50}
        autoFocus={autoFocus}
        autoComplete="off"
        autoCorrect="off"
        autoCapitalize="off"
        spellCheck="false"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={activeOptionId}
        className={cn("pr-8", inputClassName)}
      />
      {isSearching && (
        <Loader2
          size={16}
          aria-hidden="true"
          className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 animate-spin text-muted-foreground"
        />
      )}

      {isOpen && !disabled && (suggestions.length > 0 || canCreate || value.trim() !== "") && (
        <div
          id={listId}
          role="listbox"
          aria-label="Tag suggestions"
          className="absolute left-0 right-0 z-10 mt-1 max-h-60 min-w-full overflow-auto rounded-md border bg-background shadow-lg"
        >
          {suggestions.map((tag, index) => {
            const name = normalizeTagName(typeof tag === "string" ? tag : tag.name);
            const alreadyApplied = appliedKeys.has(tagKeyOf(tag));

            return (
              <button
                key={(typeof tag === "object" && tag.id) || name}
                id={`${listId}-option-${index}`}
                type="button"
                role="option"
                aria-selected={index === activeIndex}
                disabled={alreadyApplied}
                // Keep focus in the field so typing can continue after a click.
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                onClick={() => submit(tag)}
                className={cn(
                  "flex w-full items-center gap-2 px-3 py-2 text-left text-sm",
                  index === activeIndex && "bg-accent",
                  alreadyApplied && "opacity-50"
                )}
              >
                <span className="truncate">{name}</span>
                {isGlobalTag(tag) && (
                  <span className="ml-auto inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                    <Globe2 className="h-3 w-3" aria-hidden="true" /> Global
                  </span>
                )}
                {alreadyApplied && (
                  <span className={cn("shrink-0 text-xs text-muted-foreground", !isGlobalTag(tag) && "ml-auto")}>
                    already added
                  </span>
                )}
              </button>
            );
          })}

          {canCreate && (
            <button
              type="button"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => submit(value)}
              className="flex w-full items-center gap-2 border-t px-3 py-2 text-left text-sm hover:bg-accent"
            >
              <Plus className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              <span className="truncate">Create “{submission.name}”</span>
            </button>
          )}

          {suggestions.length === 0 && !canCreate && (
            <p className="px-3 py-6 text-center text-sm text-muted-foreground">
              {submission.status === "duplicate"
                ? "That tag is already applied."
                : `No tags match “${normalizeTagName(value)}”.`}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
