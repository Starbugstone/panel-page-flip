import { Button } from "@/components/ui/button";
import { displayableCoverUrl } from "@/components/metadata/provider-cover";
import { Check } from "lucide-react";

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
 * @param {() => void} props.onAcceptAll stages the fields and remembers this record
 *   as the comic's origin, which is why it is offered however few fields differ: a
 *   record matching what is already there is still the one to refresh from later
 * @param {boolean} [props.recordChosen] this is the record the form would save
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
  recordChosen = false,
  showCover = false,
  actions = null,
}) {
  const coverUrl = showCover ? displayableCoverUrl(candidate.coverUrl) : null;

  return (
    <div className="space-y-2 rounded-md border p-2">
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 gap-2">
          {coverUrl && (
            <img
              src={coverUrl}
              alt=""
              className="h-14 w-10 shrink-0 rounded object-cover"
              loading="lazy"
              referrerPolicy="no-referrer"
            />
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

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant={recordChosen ? "ghost" : "secondary"}
          size="sm"
          className="h-7"
          disabled={recordChosen}
          onClick={onAcceptAll}
        >
          {recordChosen && <Check className="mr-1 h-3 w-3" />}
          {recordChosen ? "Record chosen" : fields.length > 1 ? `Use all ${fields.length} fields` : "Use this record"}
        </Button>
        {actions}
      </div>
    </div>
  );
}
