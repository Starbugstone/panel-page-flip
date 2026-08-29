import { Check, Copy } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SHARE_CODE_TYPES, SHARING_CODE_COPY } from "@/lib/sharing";
import { MAX_CODE_USES } from "@/lib/sharing-workflow";

/**
 * Making a code instead of naming somebody: how many claims it allows, and the
 * code itself once it exists.
 *
 * The issued code is shown rather than closing the dialog, so it can be handed
 * over immediately; it can also be revealed again later from the Sharing page.
 */
export function ShareCodeOptions({
  codeType, selectedComicIds, maxUses, setMaxUses, usesValue,
  issuedCode, issuedExpiry, codeCopied, copyIssuedCode, isSending,
}) {
  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">
        {codeType === SHARE_CODE_TYPES.GROUP
          ? SHARING_CODE_COPY.groupCode
          : SHARING_CODE_COPY.comicCode}
      </p>

      {issuedCode ? (
        <div className="space-y-2 rounded-md border p-3">
          <p className="text-sm font-medium">Your code is ready</p>
          <div className="flex items-center gap-2">
            <code
              className="flex-1 rounded bg-muted px-3 py-2 font-mono text-sm tracking-widest"
              aria-label="Your new sharing code"
            >
              {issuedCode}
            </code>
            <Button variant="outline" size="sm" onClick={copyIssuedCode} aria-label="Copy the sharing code">
              {codeCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Copy it now, or show it again later under Codes you have handed out on the Sharing page.
            {issuedExpiry && ` It expires ${new Date(issuedExpiry).toLocaleString()}.`}
          </p>
        </div>
      ) : (
        <div className="grid gap-2">
          <Label htmlFor="share-code-uses">How many people may use it</Label>
          <div className="flex items-center gap-3">
            <Input
              id="share-code-uses"
              type="number"
              min={1}
              max={MAX_CODE_USES}
              value={maxUses}
              onChange={(event) => setMaxUses(
                event.target.value.replace(/[^0-9]/g, "").slice(0, 2)
              )}
              // Corrected when the field is left rather than as it
              // is typed, so what is sent is always what is shown.
              onBlur={() => setMaxUses(String(usesValue))}
              className="w-24"
              disabled={isSending}
            />
            <span className="text-xs text-muted-foreground">
              1–{MAX_CODE_USES} different people
            </span>
          </div>
          <p className="text-xs text-muted-foreground">
            {codeType === SHARE_CODE_TYPES.GROUP
              ? `A ${SHARE_CODE_TYPES.GROUP}- code for ${selectedComicIds.length} comics. Redeeming it costs one use.`
              : `A ${SHARE_CODE_TYPES.COMIC}- code for exactly one comic.`}
          </p>
        </div>
      )}
    </div>
  );
}
