import { PageLayout } from "@/components/layout/PageLayout";
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

async function fetchInvitation(token) {
  const data = await api.get(`/api/shares/invitations/${token}`, {
    // A signed-out visitor is expected here. The preview is public, so a 401
    // must not trigger the application's global signed-out handling.
    notifyUnauthorized: false,
  });
  return data.invitation;
}

function InvitationLoading() {
  return (
    <div className="flex flex-col items-center gap-3 text-center">
      <Loader2 className="h-10 w-10 animate-spin text-primary" />
      <p className="text-muted-foreground">Loading invitation…</p>
    </div>
  );
}

function InvitationFailure({ error, onDashboard }) {
  return (
    <Alert variant="destructive" className="max-w-md">
      <AlertCircle className="h-5 w-5" />
      <AlertTitle>This invitation cannot be used</AlertTitle>
      <AlertDescription className="flex flex-col items-start gap-3">
        {error}
        <Button variant="outline" size="sm" onClick={onDashboard}>Go to my collection</Button>
      </AlertDescription>
    </Alert>
  );
}

function AcceptedInvitation({ accepted, onRead, onDashboard }) {
  const { share, count } = accepted;

  return (
    <Card className="w-full max-w-md">
      <CardContent className="space-y-4 p-6 text-center">
        <BookOpen className="mx-auto h-10 w-10 text-primary" />
        <h1 className="page-title">
          {count > 1 ? `${count} comics are in your collection` : `“${share.comicTitle}” is in your collection`}
        </h1>
        <p className="text-sm text-muted-foreground">
          {count > 1 ? "They stay" : "It stays"} owned by {share.ownerName}.
          Your reading position is your own, and {count > 1 ? "a comic may" : "the comic may"}
          become unavailable if they stop sharing it.
        </p>
        <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
          {count === 1 && (
            <Button onClick={() => onRead(share.comicId)}>
              <BookOpen className="mr-2 h-4 w-4" />
              Start reading
            </Button>
          )}
          <Button variant="outline" onClick={onDashboard}>Go to my collection</Button>
        </div>
      </CardContent>
    </Card>
  );
}

function ExplicitContentWarning() {
  return (
    <Alert>
      <ShieldAlert className="h-5 w-5" />
      <AlertTitle>{EXPLICIT_GATE_TITLE}</AlertTitle>
      <AlertDescription>{EXPLICIT_GATE_BODY}</AlertDescription>
    </Alert>
  );
}

function InvitationPreview({ invitation, gated }) {
  const isFolderBatch = invitation.isFolderBatch;
  const title = isFolderBatch
    ? invitation.folderName || "Shared folder"
    : shareDisplayTitle(invitation);

  return (
    <div className="flex flex-col gap-4 sm:flex-row">
      {/* A neutral placeholder, never the real cover behind a blur: the cover
          bytes do not leave the server before age confirmation. */}
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
        <h1 className="page-title">{title}</h1>
        {invitation.comicAuthor && <p className="text-sm text-muted-foreground">{invitation.comicAuthor}</p>}
        {invitation.pageCount != null && <p className="text-sm text-muted-foreground">{invitation.pageCount} pages</p>}
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
}

function SignedOutInvitation({ invitation, gated, onLogin }) {
  return (
    <Card className="w-full max-w-lg">
      <CardContent className="space-y-4 p-6">
        <InvitationPreview invitation={invitation} gated={gated} />
        {/* Holding the link is not an age declaration; no identity has been
            established yet, so nothing about the explicit comic is revealed. */}
        {gated && <ExplicitContentWarning />}
        <p className="rounded bg-muted p-3 text-sm text-muted-foreground">
          This comic remains owned by {invitation.ownerName}. It may become unavailable if the
          owner removes it or stops sharing it.
        </p>
        <p className="text-sm">Sign in to the account this invitation was sent to in order to answer it.</p>
        <Button onClick={onLogin}>Log in to continue</Button>
      </CardContent>
    </Card>
  );
}

function WrongAccountInvitation({ invitation, gated, onDashboard }) {
  return (
    <Card className="w-full max-w-lg">
      <CardContent className="space-y-4 p-6">
        <InvitationPreview invitation={invitation} gated={gated} />
        <Alert variant="destructive">
          <AlertCircle className="h-5 w-5" />
          <AlertTitle>This invitation is for a different account</AlertTitle>
          <AlertDescription>It belongs to another account. Sign in with that account to accept it.</AlertDescription>
        </Alert>
        <Button variant="outline" onClick={onDashboard}>Go to my collection</Button>
      </CardContent>
    </Card>
  );
}

function GatedInvitation({ invitation, isAnswering, onConfirmAdult, onDecline }) {
  return (
    <Card className="w-full max-w-lg">
      <CardContent className="space-y-4 p-6">
        <InvitationPreview invitation={invitation} gated />
        <ExplicitContentWarning />
        <div className="flex flex-col gap-3 sm:flex-row">
          <Button disabled={isAnswering} onClick={onConfirmAdult}>
            {isAnswering && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {EXPLICIT_GATE_CONFIRM_LABEL}
          </Button>
          <Button variant="outline" disabled={isAnswering} onClick={onDecline}>Decline</Button>
        </div>
      </CardContent>
    </Card>
  );
}

function AnswerableInvitation({ invitation, isAnswering, onAccept, onDecline }) {
  const isFolderBatch = invitation.isFolderBatch;

  return (
    <Card className="w-full max-w-lg">
      <CardContent className="space-y-4 p-6">
        <InvitationPreview invitation={invitation} gated={false} />
        <p className="rounded bg-muted p-3 text-sm text-muted-foreground">
          {isFolderBatch ? "These comics remain" : "This comic remains"} owned by {invitation.ownerName}.
          Nothing is copied to your account: you are being given permission to read {isFolderBatch
            ? "them, and any may"
            : "it, and it may"} become unavailable if they remove it or stop sharing it.
        </p>
        <div className="flex flex-col gap-3 sm:flex-row">
          <Button disabled={isAnswering} onClick={onAccept}>
            {isAnswering && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {isFolderBatch ? `Add all ${invitation.comicCount} to my collection` : "Add to my collection"}
          </Button>
          <Button variant="outline" disabled={isAnswering} onClick={onDecline}>
            {isFolderBatch ? "Decline all" : "Decline"}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function InvitationBody({
  authLoading,
  authenticated,
  isLoading,
  error,
  accepted,
  invitation,
  isAnswering,
  onDashboard,
  onRead,
  onLogin,
  onConfirmAdult,
  onAccept,
  onDecline,
}) {
  if (authLoading || isLoading) return <InvitationLoading />;
  if (error) return <InvitationFailure error={error} onDashboard={onDashboard} />;
  if (accepted) return <AcceptedInvitation accepted={accepted} onRead={onRead} onDashboard={onDashboard} />;
  if (!invitation) return null;

  const gated = requiresAdultConfirmation(invitation);
  if (!authenticated) return <SignedOutInvitation invitation={invitation} gated={gated} onLogin={onLogin} />;
  if (!invitation.isForCurrentUser) {
    return <WrongAccountInvitation invitation={invitation} gated={gated} onDashboard={onDashboard} />;
  }
  if (gated) {
    return (
      <GatedInvitation
        invitation={invitation}
        isAnswering={isAnswering}
        onConfirmAdult={onConfirmAdult}
        onDecline={onDecline}
      />
    );
  }

  return (
    <AnswerableInvitation
      invitation={invitation}
      isAnswering={isAnswering}
      onAccept={onAccept}
      onDecline={onDecline}
    />
  );
}

function acceptedInvitation(data) {
  return { share: data.share, count: Number(data.acceptedCount) || 1 };
}

function acceptedDescription(accepted) {
  if (accepted.count > 1) return `${accepted.count} comics are now in your collection.`;
  return `${accepted.share?.comicTitle || "The comic"} is now in your collection.`;
}

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
      setInvitation(await fetchInvitation(token));
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
    fetchInvitation(token)
      .then((loadedInvitation) => { if (!ignore) setInvitation(loadedInvitation); })
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
      const acceptedResult = acceptedInvitation(data);
      setAccepted(acceptedResult);
      toast({
        title: "Added to your collection",
        description: acceptedDescription(acceptedResult),
      });
    } catch (err) {
      setLoadError(err.message || "The invitation could not be answered.");
    } finally {
      setIsAnswering(false);
    }
  };

  return (
    <PageLayout width="form" className="flex min-h-[60vh] flex-col items-center justify-center">
      <InvitationBody
        authLoading={auth.loading}
        authenticated={auth.isAuthenticated}
        isLoading={isLoading}
        error={error}
        accepted={accepted}
        invitation={invitation}
        isAnswering={isAnswering}
        onDashboard={() => navigate("/dashboard")}
        onRead={(comicId) => navigate(`/read/${comicId}`)}
        onLogin={() => navigate(`/login?redirect=/share/invitation/${token}`)}
        onConfirmAdult={confirmAdult}
        onAccept={() => answer("accept")}
        onDecline={() => answer("decline")}
      />
    </PageLayout>
  );
}
