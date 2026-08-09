import { useEffect, useState } from "react";
import { Check, Copy, KeyRound, Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useToast } from "@/hooks/use-toast";
import {
  SHARING_CODE_COPY,
  formatSharingCode,
  isValidSharingCode,
  normaliseSharingCode,
} from "@/lib/sharing";

/**
 * The two things somebody does with a code on their own account: hand out the
 * permanent one that identifies them, and redeem one they were given.
 *
 * Creating a code that gives comics away is not here. That belongs to the
 * sharing flow, because it starts by choosing comics — this card is about the
 * account, not about a library.
 */
export function SharingCodesCard({ onRedeemed }) {
  const [myCode, setMyCode] = useState(null);
  const [copied, setCopied] = useState(false);
  const [redeemValue, setRedeemValue] = useState("");
  const [isRedeeming, setIsRedeeming] = useState(false);
  const [redeemError, setRedeemError] = useState(null);
  const { toast } = useToast();

  useEffect(() => {
    let ignore = false;

    api.get("/api/shares/my-code")
      .then((data) => { if (!ignore) setMyCode(data); })
      .catch((err) => {
        if (ignore) return;
        // Not worth an error banner: the rest of the page works, and the code
        // is a convenience rather than something the page is for.
        logger.error("Could not load the sharing code:", err);
      });

    return () => { ignore = true; };
  }, []);

  const copyCode = async () => {
    try {
      await navigator.clipboard.writeText(myCode.sharingCode);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      // Clipboard access can be refused; the code is on screen and selectable,
      // so say so rather than pretending the copy worked.
      logger.error("Could not copy the sharing code:", err);
      toast({
        title: "Could not copy the code",
        description: "Select the code and copy it manually.",
        variant: "destructive",
      });
    }
  };

  const redeem = async () => {
    setIsRedeeming(true);
    setRedeemError(null);

    try {
      const data = await api.post("/api/shares/claim-codes/redeem", {
        code: normaliseSharingCode(redeemValue),
      });

      const claimed = Number(data.claimed) || 0;
      const gated = (data.results || []).filter((r) => r.status === "awaiting_age_confirmation").length;

      toast({
        title: claimed === 1 ? "Comic added" : `${claimed} comics added`,
        description: gated > 0
          ? `From ${data.ownerName}. ${gated === 1 ? "One comic needs" : `${gated} comics need`} your age confirmed below.`
          : `${data.ownerName} shared ${claimed === 1 ? "a comic" : "these"} with you.`,
      });

      setRedeemValue("");

      try {
        await onRedeemed?.();
      } catch (refreshError) {
        logger.error("Sharing data refresh failed:", refreshError);
        toast({
          title: "Comics added",
          description: "They were added, but the page could not refresh. Reload to see them.",
          variant: "destructive",
        });
      }
    } catch (err) {
      logger.error("Redeeming a sharing code failed:", err);
      setRedeemError(err.message || "That code could not be redeemed.");
    } finally {
      setIsRedeeming(false);
    }
  };

  return (
    <Card className="mb-6">
      <CardContent className="grid gap-6 p-4 md:grid-cols-2">
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <KeyRound className="h-4 w-4 text-comic-purple" />
            <h2 className="font-semibold">Your sharing code</h2>
          </div>
          <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.mine}</p>
          <div className="flex items-center gap-2">
            <code
              className="flex-1 rounded border bg-muted px-3 py-2 font-mono text-sm tracking-widest"
              aria-label="Your sharing code"
            >
              {myCode?.sharingCode || "····-····-····"}
            </code>
            <Button
              variant="outline"
              size="sm"
              disabled={!myCode}
              onClick={copyCode}
              aria-label="Copy your sharing code"
            >
              {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
            </Button>
          </div>
        </div>

        <div className="space-y-2">
          <h2 className="font-semibold">Redeem a code</h2>
          <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.redeem}</p>
          <div className="flex items-end gap-2">
            <div className="flex-1 space-y-1">
              <Label htmlFor="redeem-sharing-code" className="sr-only">Sharing code</Label>
              <Input
                id="redeem-sharing-code"
                value={redeemValue}
                onChange={(event) => setRedeemValue(formatSharingCode(event.target.value))}
                placeholder="XXXX-XXXX-XXXX"
                className="font-mono tracking-widest"
                disabled={isRedeeming}
              />
            </div>
            <Button
              onClick={redeem}
              disabled={isRedeeming || !isValidSharingCode(redeemValue)}
            >
              {isRedeeming && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Redeem
            </Button>
          </div>
          {redeemError && <p className="text-sm text-destructive">{redeemError}</p>}
        </div>
      </CardContent>
    </Card>
  );
}
