import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, Check, Copy, KeyRound, Loader2, RefreshCw, XCircle } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useToast } from "@/hooks/use-toast";
import {
  SHARE_CODE_TYPES,
  SHARING_CODE_COPY,
  formatShareCode,
  isValidShareCode,
  parseShareCode,
  shareCodeMisuse,
} from "@/lib/sharing";

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

/**
 * Who you are, and every code on your own account.
 *
 * Three things that all answer "how do people reach me, and what have I handed
 * out?": the username and `U-` code that identify you, the field for redeeming
 * a `C-` or `G-` somebody sent, and the list of codes you issued.
 *
 * *Creating* a content code is not here. That belongs to the share workflow,
 * because it starts by choosing comics — but the codes it creates come back
 * here to be watched and withdrawn, so there is one place to look.
 */
export function SharingCodesCard({ onRedeemed, reloadKey = 0 }) {
  const [identity, setIdentity] = useState(null);
  const [copied, setCopied] = useState(false);
  const [redeemValue, setRedeemValue] = useState("");
  const [isRedeeming, setIsRedeeming] = useState(false);
  const [redeemError, setRedeemError] = useState(null);
  const [handedOut, setHandedOut] = useState([]);
  const [withdrawingId, setWithdrawingId] = useState(null);
  const [confirmingRotation, setConfirmingRotation] = useState(false);
  const [isRotating, setIsRotating] = useState(false);
  const { toast } = useToast();

  // Fetches without touching state, so both the effect below and the withdraw
  // handler can decide for themselves whether their result is still wanted.
  const fetchHandedOut = useCallback(
    () => api.get("/api/shares/content-codes")
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

    api.get("/api/shares/user-code")
      .then((data) => { if (!ignore) setIdentity(data); })
      .catch((err) => {
        if (ignore) return;
        logger.error("Could not load your sharing identity:", err);
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

  const rotate = async () => {
    setIsRotating(true);

    try {
      const data = await api.post("/api/shares/user-code/rotate", {});
      setIdentity(data);
      setConfirmingRotation(false);
      toast({
        title: "User code replaced",
        description: "The old code no longer works. Send the new one to anyone who needs it.",
      });
    } catch (err) {
      logger.error("Rotating the user code failed:", err);
      toast({
        title: "Could not replace the code",
        description: err.message || "Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsRotating(false);
    }
  };

  const withdraw = async (code) => {
    setWithdrawingId(code.id);

    try {
      await api.delete(`/api/shares/content-codes/${code.id}`);
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
      await navigator.clipboard.writeText(identity.userCode);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      // Clipboard access can be refused; the code is on screen and selectable,
      // so say so rather than pretending the copy worked.
      logger.error("Could not copy the user code:", err);
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
      const data = await api.post("/api/shares/content-codes/redeem", {
        code: parseShareCode(redeemValue)?.code,
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
          ? `From ${data.ownerLabel}. ${gated === 1 ? "One comic needs" : `${gated} comics need`} your age confirmed below.`
          : `${data.ownerLabel} shared ${claimed === 1 ? "a comic" : "these"} with you.`,
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

  // A `U-` code pasted into the redeem field is not a failure — it is a real
  // code in the wrong box, and its holder can be told so immediately rather
  // than after a round trip that answers "not valid".
  const redeemMisuse = shareCodeMisuse(redeemValue, [SHARE_CODE_TYPES.COMIC, SHARE_CODE_TYPES.GROUP]);

  const describeCode = (code) => (
    code.issuedComicCount === 1 ? code.comicTitles[0] : `${code.issuedComicCount} comics`
  );

  return (
    <Card className="mb-6">
      <CardContent className="grid gap-6 p-4 md:grid-cols-2">
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <KeyRound className="h-4 w-4 text-comic-purple" />
            <h2 className="font-semibold">Your identity</h2>
          </div>
          <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.mine}</p>
          <p className="text-sm">
            Username{" "}
            <span className="font-mono font-medium" aria-label="Your username">
              {identity ? `@${identity.username}` : "…"}
            </span>
          </p>
          <div className="flex items-center gap-2">
            <code
              className="flex-1 rounded border bg-muted px-3 py-2 font-mono text-sm tracking-widest"
              aria-label="Your user code"
            >
              {identity?.userCode || "U-····-····-····"}
            </code>
            <Button
              variant="outline"
              size="sm"
              disabled={!identity}
              onClick={copyCode}
              aria-label="Copy your user code"
            >
              {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              disabled={!identity || isRotating}
              onClick={() => setConfirmingRotation(true)}
              aria-label="Replace your user code"
            >
              {isRotating
                ? <Loader2 className="h-4 w-4 animate-spin" />
                : <RefreshCw className="h-4 w-4" />}
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
                onChange={(event) => {
                  setRedeemValue(formatShareCode(event.target.value));
                  setRedeemError(null);
                }}
                placeholder="C-XXXX-XXXX-XXXX or G-XXXX-XXXX-XXXX"
                className="font-mono tracking-widest"
                disabled={isRedeeming}
              />
            </div>
            <Button
              onClick={redeem}
              disabled={
                isRedeeming
                || !isValidShareCode(redeemValue, [SHARE_CODE_TYPES.COMIC, SHARE_CODE_TYPES.GROUP])
              }
            >
              {isRedeeming && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Redeem
            </Button>
          </div>
          {redeemMisuse && (
            <p className="flex items-center gap-1 text-sm text-destructive">
              <AlertTriangle className="h-4 w-4" />
              {redeemMisuse}
            </p>
          )}
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
                      <span className="mr-2 font-mono text-xs text-muted-foreground">
                        {CODE_TYPE_LABELS[code.type] || code.type}
                      </span>
                      {describeCode(code)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {`Claimed ${code.timesUsed} of ${code.maxUses} ${code.maxUses === 1 ? "use" : "uses"}`
                        + (code.isRedeemable
                          // The server's own expiry, rendered as it came. The
                          // lifetime is an operator setting, so anything worked
                          // out here would be a guess that goes stale.
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
                        aria-label={`Withdraw the code for ${describeCode(code)}`}
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

      {/* A confirmation rather than a plain button: rotating is one click that
          silently breaks the code in every conversation it was pasted into, and
          that consequence has to be stated before it happens rather than
          explained afterwards. */}
      <Dialog open={confirmingRotation} onOpenChange={setConfirmingRotation}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Replace your user code?</DialogTitle>
            <DialogDescription>{SHARING_CODE_COPY.rotate}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmingRotation(false)} disabled={isRotating}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={rotate} disabled={isRotating}>
              {isRotating && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Replace my code
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
