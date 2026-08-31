import { ShieldAlert, UserPlus } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { SharedComicGroup } from "@/components/share/SharedComicGroup";
import { SHARING_PAGE_RESPONSIBILITY_REMINDER } from "@/lib/sharing";

const EMPTY_TARGET = { email: "", username: "", userCode: "", comicIds: [] };

/**
 * Everything the signed-in owner has handed out.
 *
 * The reminder is informational only — the acknowledgement that goes on the
 * record is the tick box in the share dialog, once per share. It is here so
 * the expectation stays visible while somebody reviews what they have already
 * handed out, not only as they hand out the next one.
 */
export function SharedByMeList({ sharedByMe, busyShareId, onShare, onStopSharing, onResend, onRevoke, onDelete }) {
  const reminder = (
    <Alert className="mb-4">
      <ShieldAlert className="h-5 w-5" />
      <AlertDescription>{SHARING_PAGE_RESPONSIBILITY_REMINDER}</AlertDescription>
    </Alert>
  );

  if (sharedByMe.length === 0) {
    return (
      <>
        {reminder}
        <div className="py-12 text-center text-muted-foreground">
          <p>You have not shared any comics yet.</p>
          <p className="mt-1 text-sm">
            Share comics privately with someone you know. Recipients must claim a code or accept
            an invitation before reading.
          </p>
          <Button className="mt-4" onClick={() => onShare(EMPTY_TARGET)}>
            <UserPlus className="mr-2 h-4 w-4" />
            Share comics
          </Button>
        </div>
      </>
    );
  }

  return (
    <div className="space-y-4">
      {reminder}
      {sharedByMe.map((group) => (
        <SharedComicGroup
          key={group.comicId}
          group={group}
          busyShareId={busyShareId}
          onShare={onShare}
          onStopSharing={onStopSharing}
          onResend={onResend}
          onRevoke={onRevoke}
          onDelete={onDelete}
        />
      ))}
    </div>
  );
}
