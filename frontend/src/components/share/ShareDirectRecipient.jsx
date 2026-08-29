import { ShareRecipientFields } from "@/components/share/ShareRecipientFields";
import { Button } from "@/components/ui/button";
import { SHARING_CODE_COPY } from "@/lib/sharing";
import { TARGETS } from "@/lib/sharing-workflow";

/**
 * Naming a person: by username, by their `U-` code, by address, or by picking
 * somebody already shared with.
 *
 * Changing any field clears the resolved identity, so the name shown beside a
 * handle is always the name that handle currently resolves to rather than one
 * left over from a previous check.
 */
export function ShareDirectRecipient({
  target, setTarget, username, setUsername, recipientEmail, setRecipientEmail,
  userCode, setUserCode, resolved, setResolved, isResolving, resolveRecipient,
  targetMisuse, recentRecipients, isSending, setError,
}) {
  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.recipient}</p>

      <div className="flex flex-wrap gap-1" role="tablist" aria-label="How to name the recipient">
        {[
          [TARGETS.USERNAME, "Username"],
          [TARGETS.CODE, "U- code"],
          [TARGETS.EMAIL, "Email address"],
        ].map(([value, label]) => (
          <Button
            key={value}
            type="button"
            role="tab"
            size="sm"
            variant={target === value ? "secondary" : "ghost"}
            aria-selected={target === value}
            disabled={isSending}
            onClick={() => { setTarget(value); setResolved(null); setError(null); }}
          >
            {label}
          </Button>
        ))}
      </div>

      {recentRecipients.length > 0 && (
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">
            Recent recipients are only people you have shared with before.
          </p>
          <div className="flex flex-wrap gap-2" aria-label="Recent recipients">
            {recentRecipients.map((recipient) => (
              <Button
                key={recipient.username || recipient.email}
                type="button"
                size="sm"
                variant="outline"
                disabled={isSending}
                onClick={() => {
                  if (recipient.username) {
                    setTarget(TARGETS.USERNAME);
                    setUsername(recipient.username);
                    // Already confirmed, and by the server: this
                    // list is people the owner has shared with
                    // before, and the label beside the button is
                    // the identity a Check would go and fetch.
                    setResolved({
                      username: recipient.username,
                      name: recipient.name || "",
                      label: recipient.label,
                    });
                  } else {
                    setResolved(null);
                    setTarget(TARGETS.EMAIL);
                    setRecipientEmail(recipient.email);
                  }
                }}
              >
                {recipient.label}
              </Button>
            ))}
          </div>
        </div>
      )}

      <ShareRecipientFields
        target={target}
        username={username}
        setUsername={setUsername}
        recipientEmail={recipientEmail}
        setRecipientEmail={setRecipientEmail}
        userCode={userCode}
        setUserCode={setUserCode}
        resolved={resolved}
        setResolved={setResolved}
        isResolving={isResolving}
        resolveRecipient={resolveRecipient}
        targetMisuse={targetMisuse}
        isSending={isSending}
      />
    </div>
  );
}
