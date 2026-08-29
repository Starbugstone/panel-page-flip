import { Button } from "@/components/ui/button";

const CONFIDENCE_LABELS = {
  exact: "Exact match",
  high: "Likely",
  ambiguous: "Possible",
  low: "Unlikely",
};

const CONFIDENCE_STYLES = {
  exact: "bg-emerald-500/15 text-emerald-700 dark:text-emerald-300",
  high: "bg-sky-500/15 text-sky-700 dark:text-sky-300",
  ambiguous: "bg-amber-500/15 text-amber-700 dark:text-amber-300",
  low: "bg-muted text-muted-foreground",
};

/**
 * One record a provider proposed, with the fields it would change.
 *
 * Two places show a candidate — the search results, and the expanded record
 * behind "Show everything" — and they differ only in the cover art and the
 * extra action beside "Use all". They were separate copies of the same markup,
 * and the confidence badge and heading had to be kept in step by hand.
 *
 * `renderField` belongs to the parent because accepting a field reads and
 * writes the staged edits the parent owns; this component decides layout and
 * nothing else.
 *
 * @param {object} props
 * @param {object} props.candidate record as the provider described it
 * @param {Array} props.fields the suggestions this record would apply
 * @param {string} props.fieldKey key prefix for the rendered fields
 * @param {(field: object, key: string) => import("react").ReactNode} props.renderField
 * @param {() => void} props.onAcceptAll
 * @param {boolean} [props.showCover] search results have room for the art; the
 *   expanded record is already headed by the same title and does not repeat it
 * @param {import("react").ReactNode} [props.actions] extra buttons beside "Use all"
 */
export function MetadataCandidateCard({
  candidate,
  fields,
  fieldKey,
  renderField,
  onAcceptAll,
  showCover = false,
  actions = null,
}) {
  const acceptAll = fields.length > 1 && (
    <Button type="button" variant="secondary" size="sm" className="h-7" onClick={onAcceptAll}>
      Use all {fields.length} fields
    </Button>
  );

  return (
    <div className="space-y-2 rounded-md border p-2">
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 gap-2">
          {showCover && candidate.coverUrl && (
            <img src={candidate.coverUrl} alt="" className="h-14 w-10 shrink-0 rounded object-cover" loading="lazy" />
          )}
          <div className="min-w-0">
            <p className="text-sm font-medium">
              {candidate.series}
              {candidate.issueNumber ? ` #${candidate.issueNumber}` : ""}
              {candidate.title ? ` — ${candidate.title}` : ""}
            </p>
            <p className="text-xs text-muted-foreground">
              {[candidate.publisher, candidate.publishedAt, candidate.provider].filter(Boolean).join(" · ")}
            </p>
          </div>
        </div>
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] ${CONFIDENCE_STYLES[candidate.confidence] ?? CONFIDENCE_STYLES.low}`}>
          {CONFIDENCE_LABELS[candidate.confidence] ?? candidate.confidence}
        </span>
      </div>

      {fields.length === 0
        ? <p className="text-xs text-muted-foreground">Matches what you already have.</p>
        : fields.map((field, index) => renderField(field, `${fieldKey}-${index}`))}

      {(acceptAll || actions) && (
        <div className="flex flex-wrap gap-2">
          {acceptAll}
          {actions}
        </div>
      )}
    </div>
  );
}
