import { Check, Copy, Eye, EyeOff, Loader2, XCircle } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { SHARE_CODE_TYPES, SHARING_CODE_COPY } from "@/lib/sharing";

/** Why a code has stopped working, in the words the owner needs. */
const DEAD_REASON_LABELS = {
  withdrawn: "Withdrawn",
  expired: "Expired",
  used_up: "Used up",
  comics_removed: "Comics removed",
};

/** What each kind of content code is called in the owner's own list. */
const CODE_TYPE_LABELS = {
  [SHARE_CODE_TYPES.COMIC]: "C- comic code",
  [SHARE_CODE_TYPES.GROUP]: "G- group code",
};

const describeCode = (code) => (
  code.issuedComicCount === 1 ? code.comicTitles[0] : `${code.issuedComicCount} comics`
);

function HandedOutCodeRow({ code, state, onToggleReveal, onCopy, onWithdraw }) {
  const what = describeCode(code);
  const shown = state.revealed[code.id];

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 p-3">
      <div className="min-w-0">
        <p className="truncate text-sm">
          <span className="mr-2 font-mono text-xs text-muted-foreground">
            {CODE_TYPE_LABELS[code.type] || code.type}
          </span>
          {what}
        </p>
        <p className="text-xs text-muted-foreground">
          {`Claimed ${code.timesUsed} of ${code.maxUses} ${code.maxUses === 1 ? "use" : "uses"}`
            + (code.isRedeemable
              // The server's own expiry, rendered as it came. The lifetime is an
              // operator setting, so anything worked out here would be a guess
              // that goes stale.
              ? ` · expires ${new Date(code.expiresAt).toLocaleString()}`
              : "")}
        </p>
        {shown && (
          <div className="mt-2 flex items-center gap-2">
            <code className="rounded border bg-muted px-2 py-1 font-mono text-sm tracking-widest">{shown}</code>
            <Button size="sm" variant="outline" onClick={onCopy} aria-label={`Copy the code for ${what}`}>
              {state.copiedCodeId === code.id ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
            </Button>
          </div>
        )}
      </div>
      <div className="flex items-center gap-2">
        <Badge variant={code.isRedeemable ? "default" : "outline"}>
          {DEAD_REASON_LABELS[code.deadReason] || "Active"}
        </Badge>
        {/* Offered on dead codes too: "which one was that?" is a question about
            a withdrawn code as much as a live one, and answering it hands over
            nothing redeemable. */}
        {code.canReveal && (
          <Button
            size="sm"
            variant="ghost"
            disabled={state.revealingId === code.id}
            aria-label={shown ? `Hide the code for ${what}` : `Show the code for ${what}`}
            onClick={onToggleReveal}
          >
            {state.revealingId === code.id
              ? <Loader2 className="h-4 w-4 animate-spin" />
              : shown ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            <span className="ml-2 hidden sm:inline">{shown ? "Hide" : "Show"}</span>
          </Button>
        )}
        {code.isRedeemable && (
          <Button
            size="sm"
            variant="ghost"
            disabled={state.withdrawingId === code.id}
            aria-label={`Withdraw the code for ${what}`}
            onClick={onWithdraw}
          >
            {state.withdrawingId === code.id
              ? <Loader2 className="h-4 w-4 animate-spin" />
              : <XCircle className="h-4 w-4" />}
            <span className="ml-2 hidden sm:inline">Withdraw</span>
          </Button>
        )}
      </div>
    </li>
  );
}

/** Every content code this account has issued, live or spent. */
export function HandedOutCodesList({ handedOut }) {
  if (handedOut.codes.length === 0) return null;

  return (
    <div className="space-y-2 border-t pt-4 md:col-span-2">
      <h2 className="font-semibold">Codes you have handed out</h2>
      <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.handedOut}</p>
      <ul className="divide-y rounded-md border">
        {handedOut.codes.map((code) => (
          <HandedOutCodeRow
            key={code.id}
            code={code}
            state={handedOut}
            onToggleReveal={() => handedOut.toggleReveal(code)}
            onCopy={() => handedOut.copyCode(code)}
            onWithdraw={() => handedOut.withdraw(code)}
          />
        ))}
      </ul>
    </div>
  );
}
