import { useCallback, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { useSharing } from "@/hooks/use-sharing";
import { useComicLibrary } from "@/hooks/use-comic-library";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { AlertCircle, BookOpen, Loader2, ShieldAlert } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import {
  EXPLICIT_GATE_BODY,
  EXPLICIT_GATE_CONFIRM_LABEL,
  EXPLICIT_GATE_TITLE,
  requiresAdultConfirmation,
  shareDisplayTitle,
} from "@/lib/sharing";

/**
 * The page an invitation link opens.
 *
 * Loading it only reads: the token is exchanged for a description of what is
 * being offered through a GET, and nothing is accepted or declined until a
 * button is pressed. Mail scanners and link-preview services follow links on
 * the recipient's behalf, so a link that acted on its own would answer
 * invitations nobody had seen.
 */
export default function ShareInvitation() {
  const { token } = useParams();
  const navigate = useNavigate();
  const auth = useAuth();
  const { toast } = useToast();
  const { refreshSummary } = useSharing();
  const { loadLibrary } = useComicLibrary();

  const [invitation, setInvitation] = useState(null);
  const [isFetching, setIsFetching] = useState(true);
  const [isAnswering, setIsAnswering] = useState(false);
  const [loadError, setLoadError] = useState(null);
  const [accepted, setAccepted] = useState(null);

  const loadInvitation = useCallback(async () => {
    if (!token) return;

    setIsFetching(true);
    setLoadError(null);
    try {
      const data = await api.get(`/api/shares/invitations/${token}`, {
        // A signed-out visitor is expected here — the preview is public so an
        // invited person can see what they are being offered before creating an
        // account — so a 401 must not tip the app into its logged-out handling.
        notifyUnauthorized: false,
      });
      setInvitation(data.invitation);
    } catch (err) {
      logger.error("Failed to load invitation:", err);
      setLoadError(err.message || "This invitation could not be loaded.");
    } finally {
      setIsFetching(false);
    }
  }, [token]);

  // loadInvitation is re-run after answering, where showing the spinner
  // straight away is right. Mounting asks directly so the preview does not
  // render twice before the request exists.
  useEffect(() => {
    if (!token) return undefined;

    let ignore = false;
    api.get(`/api/shares/invitations/${token}`, { notifyUnauthorized: false })
      .then((data) => { if (!ignore) setInvitation(data.invitation); })
      .catch((err) => {
        if (ignore) return;
        logger.error("Failed to load invitation:", err);
        setLoadError(err.message || "This invitation could not be loaded.");
      })
      .finally(() => { if (!ignore) setIsFetching(false); });

    return () => { ignore = true; };
  }, [token]);

  // A link with no token is already an error, not something to wait for, so
  // both of these follow from the token rather than being written alongside it.
  const missingToken = !token;
  const isLoading = !missingToken && isFetching;
  const error = missingToken ? "This link does not contain an invitation." : loadError;

  /**
   * Make the age declaration, then redisplay whatever the server chooses to
   * reveal in response.
   *
   * The preview is reloaded rather than patched from local state: the unlocked
   * metadata only exists because the backend decided to send it, and rendering
   * it any earlier would be the client deciding instead.
   */
  const confirmAdult = async () => {
    setIsAnswering(true);
    setLoadError(null);
    try {
      await api.post(`/api/shares/invitations/${token}/confirm-adult`, { adultConfirmed: true });
      await loadInvitation();
    } catch (err) {
      setLoadError(err.message || "Your age could not be confirmed.");
    } finally {
      setIsAnswering(false);
    }
  };

  const answer = async (decision) => {
    setIsAnswering(true);
    setLoadError(null);
    try {
      const data = await api.post(`/api/shares/invitations/${token}/${decision}`, {});
      await refreshSummary();

      if (decision === "decline") {
        toast({ title: "Invitation declined" });
        navigate("/sharing", { replace: true });
        return;
      }

      await loadLibrary();
      setAccepted({ share: data.share, count: Number(data.acceptedCount) || 1 });
      toast({
        title: "Added to your collection",
        description: (Number(data.acceptedCount) || 1) > 1
          ? `${data.acceptedCount} comics are now in your collection.`
          : `${data.share?.comicTitle || "The comic"} is now in your collection.`,
      });
    } catch (err) {
      setLoadError(err.message || "The invitation could not be answered.");
    } finally {
      setIsAnswering(false);
    }
  };

  const renderBody = () => {
    if (auth.loading || isLoading) {
      return (
        <div className="flex flex-col items-center gap-3 text-center">
          <Loader2 className="h-10 w-10 animate-spin text-primary" />
          <p className="text-muted-foreground">Loading invitation…</p>
        </div>
      );
    }

    if (error) {
      return (
        <Alert variant="destructive" className="max-w-md">
          <AlertCircle className="h-5 w-5" />
          <AlertTitle>This invitation cannot be used</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-3">
            {error}
            <Button variant="outline" size="sm" onClick={() => navigate("/dashboard")}>
              Go to my collection
            </Button>
          </AlertDescription>
        </Alert>
      );
    }

    if (accepted) {
      const acceptedShare = accepted.share;
      return (
        <Card className="w-full max-w-md">
          <CardContent className="space-y-4 p-6 text-center">
            <BookOpen className="mx-auto h-10 w-10 text-comic-purple" />
            <h1 className="font-comic text-2xl">
              {accepted.count > 1
                ? `${accepted.count} comics are in your collection`
                : `“${acceptedShare.comicTitle}” is in your collection`}
            </h1>
            <p className="text-sm text-muted-foreground">
              {accepted.count > 1 ? "They stay" : "It stays"} owned by {acceptedShare.ownerName}.
              Your reading position is your own, and {accepted.count > 1 ? "a comic may" : "the comic may"}
              become unavailable if they stop sharing it.
            </p>
            <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
              {accepted.count === 1 && (
                <Button onClick={() => navigate(`/read/${acceptedShare.comicId}`)}>
                  <BookOpen className="mr-2 h-4 w-4" />
                  Start reading
                </Button>
              )}
              <Button variant="outline" onClick={() => navigate("/dashboard")}>
                Go to my collection
              </Button>
            </div>
          </CardContent>
        </Card>
      );
    }

    if (!invitation) return null;

    const gated = requiresAdultConfirmation(invitation);
    const isFolderBatch = invitation.isFolderBatch;
    const invitationTitle = isFolderBatch
      ? (invitation.folderName || "Shared folder")
      : shareDisplayTitle(invitation);

    const explicitWarning = (
      <Alert>
        <ShieldAlert className="h-5 w-5" />
        <AlertTitle>{EXPLICIT_GATE_TITLE}</AlertTitle>
        <AlertDescription>{EXPLICIT_GATE_BODY}</AlertDescription>
      </Alert>
    );

    const preview = (
      <div className="flex gap-4">
        {/* A neutral placeholder, never the real cover behind a blur: blurring
            still sends the cover, and the point of the gate is that those bytes
            do not leave the server until somebody has confirmed. The backend
            withholds the URL entirely, so there is nothing here to blur. */}
        {invitation.coverImagePath ? (
          <img
            src={invitation.coverImagePath}
            alt={`Cover of ${invitation.comicTitle}`}
            className="h-40 w-28 flex-none rounded object-cover"
          />
        ) : (
          <div className="flex h-40 w-28 flex-none items-center justify-center rounded bg-muted">
            {gated
              ? <ShieldAlert className="h-8 w-8 text-muted-foreground" />
              : <BookOpen className="h-8 w-8 text-muted-foreground" />}
          </div>
        )}
        <div className="min-w-0 space-y-1">
          <h1 className="font-comic text-2xl">{invitationTitle}</h1>
          {invitation.comicAuthor && (
            <p className="text-sm text-muted-foreground">{invitation.comicAuthor}</p>
          )}
          {invitation.pageCount != null && (
            <p className="text-sm text-muted-foreground">{invitation.pageCount} pages</p>
          )}
          <p className="pt-2 text-sm">
            <span className="font-medium">{invitation.ownerName}</span> wants to share {isFolderBatch
              ? `${invitation.comicCount} comics from this folder`
              : "this comic"} with you.
          </p>
          {invitation.expiresAt && (
            <p className="text-sm text-muted-foreground">
              This invitation expires on {new Date(invitation.expiresAt).toLocaleDateString()}.
            </p>
          )}
        </div>
      </div>
    );

    if (!auth.isAuthenticated) {
      return (
        <Card className="w-full max-w-lg">
          <CardContent className="space-y-4 p-6">
            {preview}
            {/* Holding the link is not an age declaration — nobody has said who
                they are yet — so a signed-out visitor is told what the
                invitation is and nothing about the comic. */}
            {gated && explicitWarning}
            <p className="rounded bg-muted p-3 text-sm text-muted-foreground">
              This comic remains owned by {invitation.ownerName}. It may become unavailable if the
              owner removes it or stops sharing it.
            </p>
            {/* The address is withheld from anyone the server cannot identify
                as the recipient, so a forwarded link does not disclose who was
                invited. Signed out, that is always the case. */}
            <p className="text-sm">
              Sign in to the account this invitation was sent to in order to answer it.
            </p>
            <Button onClick={() => navigate(`/login?redirect=/share/invitation/${token}`)}>
              Log in to continue
            </Button>
          </CardContent>
        </Card>
      );
    }

    if (!invitation.isForCurrentUser) {
      return (
        <Card className="w-full max-w-lg">
          <CardContent className="space-y-4 p-6">
            {preview}
            <Alert variant="destructive">
              <AlertCircle className="h-5 w-5" />
              <AlertTitle>This invitation is for a different account</AlertTitle>
              <AlertDescription>
                It belongs to another account. Sign in with that account to accept it.
              </AlertDescription>
            </Alert>
            <Button variant="outline" onClick={() => navigate("/dashboard")}>
              Go to my collection
            </Button>
          </CardContent>
        </Card>
      );
    }

    // The recipient, signed in, on an explicit comic they have not confirmed
    // for. Accepting is not offered at all here: it is the age declaration that
    // has to come first, and the backend refuses an accept without it anyway.
    if (gated) {
      return (
        <Card className="w-full max-w-lg">
          <CardContent className="space-y-4 p-6">
            {preview}
            {explicitWarning}
            <div className="flex flex-col gap-3 sm:flex-row">
              <Button disabled={isAnswering} onClick={confirmAdult}>
                {isAnswering && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {EXPLICIT_GATE_CONFIRM_LABEL}
              </Button>
              <Button variant="outline" disabled={isAnswering} onClick={() => answer("decline")}>
                Decline
              </Button>
            </div>
          </CardContent>
        </Card>
      );
    }

    return (
      <Card className="w-full max-w-lg">
        <CardContent className="space-y-4 p-6">
          {preview}
          <p className="rounded bg-muted p-3 text-sm text-muted-foreground">
            {isFolderBatch ? "These comics remain" : "This comic remains"} owned by {invitation.ownerName}.
            Nothing is copied to your account: you are being given permission to read {isFolderBatch
              ? "them, and any may"
              : "it, and it may"} become unavailable if they remove it or stop sharing it.
          </p>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button disabled={isAnswering} onClick={() => answer("accept")}>
              {isAnswering && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {isFolderBatch ? `Add all ${invitation.comicCount} to my collection` : "Add to my collection"}
            </Button>
            <Button variant="outline" disabled={isAnswering} onClick={() => answer("decline")}>
              {isFolderBatch ? "Decline all" : "Decline"}
            </Button>
          </div>
        </CardContent>
      </Card>
    );
  };

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center bg-background p-4">
      {renderBody()}
    </div>
  );
}
