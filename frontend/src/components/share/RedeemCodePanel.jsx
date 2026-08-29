import { AlertTriangle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SHARE_CODE_TYPES, SHARING_CODE_COPY, isValidShareCode, shareCodeMisuse } from "@/lib/sharing";

const REDEEMABLE = [SHARE_CODE_TYPES.COMIC, SHARE_CODE_TYPES.GROUP];

/** Claiming a code somebody sent. */
export function RedeemCodePanel({ value, onChange, isRedeeming, error, onRedeem }) {
  // A `U-` code pasted here is not a failure — it is a real code in the wrong
  // box, and its holder can be told so immediately rather than after a round
  // trip that answers "not valid".
  const misuse = shareCodeMisuse(value, REDEEMABLE);

  return (
    <div className="space-y-2">
      <h2 className="font-semibold">Redeem a code</h2>
      <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.redeem}</p>
      <div className="flex items-end gap-2">
        <div className="flex-1 space-y-1">
          <Label htmlFor="redeem-sharing-code" className="sr-only">Sharing code</Label>
          <Input
            id="redeem-sharing-code"
            value={value}
            onChange={(event) => onChange(event.target.value)}
            placeholder="C-XXXX-XXXX-XXXX or G-XXXX-XXXX-XXXX"
            className="font-mono tracking-widest"
            disabled={isRedeeming}
          />
        </div>
        <Button onClick={onRedeem} disabled={isRedeeming || !isValidShareCode(value, REDEEMABLE)}>
          {isRedeeming && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          Redeem
        </Button>
      </div>
      {misuse && (
        <p className="flex items-center gap-1 text-sm text-destructive">
          <AlertTriangle className="h-4 w-4" />
          {misuse}
        </p>
      )}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
