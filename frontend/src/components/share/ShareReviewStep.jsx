import { AlertTriangle } from "lucide-react";

import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { SHARE_RESPONSIBILITY_ACK_LABEL, SHARE_RESPONSIBILITY_NOTICE } from "@/lib/sharing";

/**
 * Step three: what is about to happen, said in full before it happens.
 *
 * The 18+ control lives here rather than in the library because the moment
 * somebody decides a comic is adult is the moment they are about to hand it to
 * someone else. It only ever promotes: leaving it unticked never clears a
 * classification somebody made deliberately.
 */
export function ShareReviewStep({
  reviewSummary, confirmationPending, selectedComics, selectedComicIds, lockSelection,
  markExplicit, setMarkExplicit, alreadyExplicitCount, responsibilityAccepted,
  setResponsibilityAccepted, isSending,
}) {
  return (
    <section className="space-y-3">
      <div>
        <h3 className="font-semibold">3. Review</h3>
        <p className="text-sm text-muted-foreground">{reviewSummary}</p>
      </div>

      {confirmationPending && (
        <p className="flex items-center gap-1 text-sm text-amber-600 dark:text-amber-500">
          <AlertTriangle className="h-4 w-4 shrink-0" />
          Check who this is before sending, so you can see whose library it goes to.
        </p>
      )}

      {selectedComics.length > 0 && !lockSelection && (
        <ul className="max-h-28 list-disc overflow-y-auto pl-5 text-sm">
          {selectedComics.map((comic) => <li key={comic.id}>{comic.title}</li>)}
        </ul>
      )}

      {/* Reachable from here because the moment somebody decides a comic
          is adult is the moment they are about to hand it over. It only
          ever promotes: unticking it does not clear a classification
          somebody made deliberately. */}
      <div className="space-y-2 rounded-md border p-3">
        <div className="flex items-center gap-2">
          {/* Disabled on the ids being sent, not on the ones the picker
              fetched. Otherwise the entry point this control exists for
              — a table selection somebody is about to hand over — is the
              one that cannot reach it. */}
          <Checkbox
            id="share-mark-explicit"
            checked={markExplicit}
            onCheckedChange={(checked) => setMarkExplicit(checked === true)}
            disabled={isSending || selectedComicIds.length === 0}
          />
          <Label htmlFor="share-mark-explicit" className="cursor-pointer text-sm font-medium">
            These comic(s) contain 18+ / explicit content
          </Label>
        </div>
        <p className="text-xs text-muted-foreground">
          {alreadyExplicitCount > 0
            ? `${alreadyExplicitCount} of these ${alreadyExplicitCount === 1 ? "is" : "are"} already marked 18+. `
              + "Leaving this unticked never clears an existing mark."
            : "Ticking this marks the selected comics 18+ on your own library, and recipients "
              + "must confirm their age and accept before they can read them."}
        </p>
      </div>

      <div className="space-y-3 rounded-md border p-3">
        <p className="text-sm text-muted-foreground">{SHARE_RESPONSIBILITY_NOTICE}</p>
        <div className="flex items-center gap-2">
          <Checkbox
            id="share-responsibility"
            checked={responsibilityAccepted}
            onCheckedChange={(checked) => setResponsibilityAccepted(checked === true)}
            disabled={isSending}
          />
          <Label htmlFor="share-responsibility" className="cursor-pointer text-sm font-medium">
            {SHARE_RESPONSIBILITY_ACK_LABEL}
          </Label>
        </div>
      </div>
    </section>
  );
}
