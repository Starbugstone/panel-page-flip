import { useCallback, useEffect, useState } from "react";
import { Check, Copy, KeyRound, Loader2, XCircle } from "lucide-react";

import { Badge } from "@/components/ui/badge";
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

/** Why a code has stopped working, in the words the owner needs. */
const DEAD_REASON_LABELS = {
  withdrawn: "Withdrawn",
  expired: "Expired",
  used_up: "Used up",
  comics_removed: "Comics removed",
};

/**
 * Everything to do with codes on your own account: the permanent one that
 * identifies you, redeeming one you were given, and withdrawing one you handed
 * out before it would have died on its own.
 *
 * *Creating* a code that gives comics away is not here. That belongs to the
 * sharing flow, because it starts by choosing comics — but the codes it creates
 * come back here to be watched and withdrawn, so there is one place to look.
 */
export function SharingCodesCard({ onRedeemed, reloadKey = 0 }) {
  const [myCode, setMyCode] = useState(null);
  const [copied, setCopied] = useState(false);
  const [redeemValue, setRedeemValue] = useState("");
  const [isRedeeming, setIsRedeeming] = useState(false);
  const [redeemError, setRedeemError] = useState(null);
  const [handedOut, setHandedOut] = useState([]);
  const [withdrawingId, setWithdrawingId] = useState(null);
  const { toast } = useToast();

  // Fetches without touching state, so both the effect below and the withdraw
  // handler can decide for themselves whether their result is still wanted.
  const fetchHandedOut = useCallback(
    () => api.get("/api/shares/claim-codes")
      .then((data) => data.codes || [])
      .catch((err) => {
        // Not worth an error banner: the rest of the page works, and this list
        // is a record of what was handed out rather than what the page is for.
        logger.error("Could not load handed-out sharing codes:", err);

        return null;
      }),
    []
  );

  useEffect(() => {
    let ignore = false;

    api.get("/api/shares/my-code")
      .then((data) => { if (!ignore) setMyCode(data); })
      .catch((err) => {
        if (ignore) return;
        logger.error("Could not load the sharing code:", err);
      });

    return () => { ignore = true; };
  }, []);

  // Reloaded when the page says something changed — creating a code from the
  // share dialog is what usually adds one to this list.
  useEffect(() => {
    let ignore = false;

    fetchHandedOut().then((codes) => {
      if (!ignore && codes) setHandedOut(codes);
    });

    return () => { ignore = true; };
  }, [fetchHandedOut, reloadKey]);

  const withdraw = async (code) => {
    setWithdrawingId(code.id);

    try {
      await api.delete(`/api/shares/claim-codes/${code.id}`);
      toast({
        title: "Sharing code withdrawn",
        description: "Nobody else can use it. Anyone who already claimed a comic keeps it — "
          + "revoke them from Shared by me if you want it back.",
      });
      const codes = await fetchHandedOut();
      if (codes) setHandedOut(codes);
    } catch (err) {
      logger.error("Withdrawing a sharing code failed:", err);
      toast({
        title: "Could not withdraw the code",
        description: err.message || "Please try again.",
        variant: "destructive",
      });
    } finally {
      setWithdrawingId(null);
    }
  };

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

      // A code can be spent without adding anything — every comic on it may
      // already be shared with this account. "0 comics added" as a success
      // toast reads like the feature working; it is not.
      if (claimed === 0) {
        setRedeemError(
          (data.results || []).find((result) => result.message)?.message
            || "That code added nothing new to your collection."
        );
        return;
      }

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

        {handedOut.length > 0 && (
          <div className="space-y-2 border-t pt-4 md:col-span-2">
            <h2 className="font-semibold">Codes you have handed out</h2>
            <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.handedOut}</p>
            <ul className="divide-y rounded-md border">
              {handedOut.map((code) => (
                <li key={code.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm">
                      {code.comicCount === 1 ? code.comicTitles[0] : `${code.comicCount} comics`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {`Claimed ${code.timesUsed} of ${code.maxUses} ${code.maxUses === 1 ? "use" : "uses"}`
                        + (code.isRedeemable
                          ? ` · expires ${new Date(code.expiresAt).toLocaleString()}`
                          : "")}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={code.isRedeemable ? "default" : "outline"}>
                      {DEAD_REASON_LABELS[code.deadReason] || "Active"}
                    </Badge>
                    {code.isRedeemable && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={withdrawingId === code.id}
                        aria-label={`Withdraw the code for ${code.comicCount === 1 ? code.comicTitles[0] : `${code.comicCount} comics`}`}
                        onClick={() => withdraw(code)}
                      >
                        {withdrawingId === code.id
                          ? <Loader2 className="h-4 w-4 animate-spin" />
                          : <XCircle className="h-4 w-4" />}
                        <span className="ml-2 hidden sm:inline">Withdraw</span>
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
