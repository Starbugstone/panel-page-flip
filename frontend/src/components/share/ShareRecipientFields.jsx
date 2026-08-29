import { AlertTriangle, Loader2, UserCheck } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SHARE_CODE_TYPES, formatShareCode, isValidShareCode, isValidUsername } from "@/lib/sharing";
import { TARGETS } from "@/lib/sharing-workflow";

/**
 * The field for whichever way the sender chose to name the recipient, and the
 * identity it currently resolves to.
 *
 * Typing in any of them clears that identity, so the name on screen is never
 * one left over from a handle that has since been edited.
 */
export function ShareRecipientFields({
  target, username, setUsername, recipientEmail, setRecipientEmail, userCode, setUserCode,
  resolved, setResolved, isResolving, resolveRecipient, targetMisuse, isSending,
}) {
  return (
    <>
      {target === TARGETS.USERNAME && (
        <div className="grid gap-2">
          <Label htmlFor="share-username">Their username</Label>
          <div className="flex gap-2">
            <Input
              id="share-username"
              autoComplete="off"
              value={username}
              onChange={(event) => { setUsername(event.target.value); setResolved(null); }}
              placeholder="@SilverOtter4821"
              disabled={isSending}
            />
            <Button
              type="button"
              variant="outline"
              onClick={resolveRecipient}
              disabled={isSending || isResolving || !isValidUsername(username)}
            >
              {isResolving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Check
            </Button>
          </div>
        </div>
      )}

      {target === TARGETS.CODE && (
        <div className="grid gap-2">
          <Label htmlFor="share-user-code">Their U- code</Label>
          <div className="flex gap-2">
            <Input
              id="share-user-code"
              autoComplete="off"
              value={userCode}
              onChange={(event) => {
                setUserCode(formatShareCode(event.target.value));
                // A changed code is a different person until checked.
                setResolved(null);
              }}
              placeholder="U-XXXX-XXXX-XXXX"
              className="font-mono tracking-widest"
              disabled={isSending}
            />
            <Button
              type="button"
              variant="outline"
              onClick={resolveRecipient}
              disabled={
                isSending || isResolving || !isValidShareCode(userCode, SHARE_CODE_TYPES.USER)
              }
            >
              {isResolving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Check
            </Button>
          </div>
          {targetMisuse && (
            <p className="flex items-center gap-1 text-sm text-destructive">
              <AlertTriangle className="h-4 w-4" />
              {targetMisuse}
            </p>
          )}
        </div>
      )}

      {target === TARGETS.EMAIL && (
        <div className="grid gap-2">
          <Label htmlFor="share-email">Recipient email</Label>
          <Input
            id="share-email"
            type="email"
            autoComplete="off"
            value={recipientEmail}
            onChange={(event) => setRecipientEmail(event.target.value)}
            placeholder="recipient@example.com"
            disabled={isSending}
          />
          <p className="text-xs text-muted-foreground">
            Use an address for somebody who has no account here yet.
          </p>
        </div>
      )}

      {resolved && (
        <p className="flex items-center gap-1 text-sm text-muted-foreground">
          <UserCheck className="h-4 w-4" />
          Sharing with <span className="font-medium">{resolved.label}</span>
        </p>
      )}
    </>
  );
}
