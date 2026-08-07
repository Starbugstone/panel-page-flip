import { useCallback, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { useSharing } from "@/hooks/use-sharing";
import { useComicLibrary } from "@/hooks/use-comic-library";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { AlertCircle, BookOpen, Loader2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

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
  const [isLoading, setIsLoading] = useState(true);
  const [isAnswering, setIsAnswering] = useState(false);
  const [error, setError] = useState(null);
  const [accepted, setAccepted] = useState(null);

  const loadInvitation = useCallback(async () => {
    if (!token) {
      setError("This link does not contain an invitation.");
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setError(null);
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
      setError(err.message || "This invitation could not be loaded.");
    } finally {
      setIsLoading(false);
    }
  }, [token]);

  useEffect(() => {
    loadInvitation();
  }, [loadInvitation]);

  const answer = async (decision) => {
    setIsAnswering(true);
    setError(null);
    try {
      const data = await api.post(`/api/shares/invitations/${token}/${decision}`, {});
      await refreshSummary();

      if (decision === "decline") {
        toast({ title: "Invitation declined" });
        navigate("/sharing", { replace: true });
        return;
      }

      await loadLibrary();
      setAccepted(data.share);
      toast({
        title: "Added to your collection",
        description: `${data.share?.comicTitle || "The comic"} is now in your collection.`,
      });
    } catch (err) {
      setError(err.message || "The invitation could not be answered.");
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
      return (
        <Card className="w-full max-w-md">
          <CardContent className="space-y-4 p-6 text-center">
            <BookOpen className="mx-auto h-10 w-10 text-comic-purple" />
            <h1 className="font-comic text-2xl">“{accepted.comicTitle}” is in your collection</h1>
            <p className="text-sm text-muted-foreground">
              It stays owned by {accepted.ownerName}. Your reading position is your own, and the
              comic may become unavailable if they stop sharing it.
            </p>
            <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
              <Button onClick={() => navigate(`/read/${accepted.comicId}`)}>
                <BookOpen className="mr-2 h-4 w-4" />
                Start reading
              </Button>
              <Button variant="outline" onClick={() => navigate("/dashboard")}>
                Go to my collection
              </Button>
            </div>
          </CardContent>
        </Card>
      );
    }

    if (!invitation) return null;

    const preview = (
      <div className="flex gap-4">
        {invitation.coverImagePath ? (
          <img
            src={invitation.coverImagePath}
            alt={`Cover of ${invitation.comicTitle}`}
            className="h-40 w-28 flex-none rounded object-cover"
          />
        ) : (
          <div className="flex h-40 w-28 flex-none items-center justify-center rounded bg-muted">
            <BookOpen className="h-8 w-8 text-muted-foreground" />
          </div>
        )}
        <div className="min-w-0 space-y-1">
          <h1 className="font-comic text-2xl">{invitation.comicTitle}</h1>
          {invitation.comicAuthor && (
            <p className="text-sm text-muted-foreground">{invitation.comicAuthor}</p>
          )}
          {invitation.pageCount != null && (
            <p className="text-sm text-muted-foreground">{invitation.pageCount} pages</p>
          )}
          <p className="pt-2 text-sm">
            <span className="font-medium">{invitation.ownerName}</span> wants to share this comic
            with you.
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
                It was sent to another address. Sign in with that account to accept it.
              </AlertDescription>
            </Alert>
            <Button variant="outline" onClick={() => navigate("/dashboard")}>
              Go to my collection
            </Button>
          </CardContent>
        </Card>
      );
    }

    return (
      <Card className="w-full max-w-lg">
        <CardContent className="space-y-4 p-6">
          {preview}
          <p className="rounded bg-muted p-3 text-sm text-muted-foreground">
            This comic remains owned by {invitation.ownerName}. Nothing is copied to your account:
            you are being given permission to read their comic, and it may become unavailable if
            they remove it or stop sharing it.
          </p>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button disabled={isAnswering} onClick={() => answer("accept")}>
              {isAnswering && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Add to my collection
            </Button>
            <Button variant="outline" disabled={isAnswering} onClick={() => answer("decline")}>
              Decline
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
