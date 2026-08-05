import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Loader2, AlertCircle, CheckCircle2, BookOpen } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";

export default function AcceptSharePage() {
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [successMessage, setSuccessMessage] = useState(null);
  const [acceptedComicTitle, setAcceptedComicTitle] = useState(null);
  const [acceptedComicId, setAcceptedComicId] = useState(null);

  const { token } = useParams();
  const navigate = useNavigate();
  const auth = useAuth();
  const { toast } = useToast();

  useEffect(() => {
    if (auth.loading) {
      // Wait for authentication status to be determined
      setIsLoading(true);
      return;
    }

    if (!auth.isAuthenticated) {
      setIsLoading(false);
      // Error state is handled by the UI rendering logic for !auth.isAuthenticated
      return;
    }

    if (token && auth.isAuthenticated) {
      // The recipient must make an explicit choice; visiting the link does not
      // copy anything into their library.
      setIsLoading(false);
    } else if (!token) {
      setError("No share token provided.");
      setIsLoading(false);
    }
  }, [auth.isAuthenticated, auth.loading, token]);

  const acceptShare = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await api.post(`/api/share/accept/${token}`, {});
      setAcceptedComicTitle(data.comic?.title || "the comic");
      setAcceptedComicId(data.comic?.id);
      setSuccessMessage("Comic successfully added to your library!");
      toast({
        title: "Comic Added to Library",
        description: `${data.comic?.title || "Comic"} has been added to your collection.`,
        duration: 5000,
      });
    } catch (err) {
      setError(err.message || "An unexpected error occurred while accepting the share.");
    } finally {
      setIsLoading(false);
    }
  };

  const refuseShare = async () => {
    setIsLoading(true);
    setError(null);
    try {
      await api.post(`/api/share/refuse/${token}`, {});
      toast({ title: "Invitation declined" });
      navigate("/dashboard", { replace: true });
    } catch (err) {
      setError(err.message || "The invitation could not be declined.");
      setIsLoading(false);
    }
  };

  const renderContent = () => {
    if (auth.loading || isLoading) {
      return (
        <div className="flex flex-col items-center justify-center text-center">
          <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
          <p className="text-lg text-muted-foreground">Loading, please wait...</p>
        </div>
      );
    }

    if (!auth.isAuthenticated) {
      return (
        <Alert variant="default" className="max-w-md text-center">
          <AlertCircle className="h-5 w-5" />
          <AlertTitle>Authentication Required</AlertTitle>
          <AlertDescription className="mb-4">
            You need to be logged in to accept this comic share.
          </AlertDescription>
          <Button
            onClick={() => navigate(`/login?redirect=/share/accept/${token}`)}
            className="mt-4"
          >
            Log In
          </Button>
        </Alert>
      );
    }

    if (error) {
      return (
        <Alert variant="destructive" className="max-w-md text-center">
          <AlertCircle className="h-5 w-5" />
          <AlertTitle>Share invitation error</AlertTitle>
          <AlertDescription className="mb-4">{error}</AlertDescription>
          <Button onClick={() => navigate("/dashboard")} variant="outline" className="mt-4">
            Go to Dashboard
          </Button>
        </Alert>
      );
    }

    if (successMessage) {
      return (
        <Alert variant="default" className="max-w-md text-center border-green-500">
          <CheckCircle2 className="h-5 w-5 text-green-600" />
          <AlertTitle className="text-green-700">Share Accepted!</AlertTitle>
          <AlertDescription className="mb-4">
            Success! '{acceptedComicTitle}' has been added to your collection.
          </AlertDescription>
          <div className="flex flex-col sm:flex-row gap-3 mt-4 justify-center">
            <Button 
              onClick={() => navigate("/dashboard")} 
              className="flex items-center gap-2">
              Go to Dashboard
            </Button>
            <Button 
              variant="outline" 
              onClick={() => navigate(`/read/${acceptedComicId}`)} 
              className="flex items-center gap-2"
              disabled={!acceptedComicId}>
              <BookOpen className="h-4 w-4" />
              Start Reading
            </Button>
          </div>
        </Alert>
      );
    }

    return (
      <Alert variant="default" className="max-w-md">
        <BookOpen className="h-5 w-5" />
        <AlertTitle>Comic invitation</AlertTitle>
        <AlertDescription>
          Accepting this invitation copies the shared comic into your library and
          records your reading progress. Nothing is added until you choose Accept.
        </AlertDescription>
        <div className="mt-4 flex flex-col gap-3 sm:flex-row">
          <Button onClick={acceptShare}>Accept into my library</Button>
          <Button variant="outline" onClick={refuseShare}>Decline invitation</Button>
        </div>
      </Alert>
    );
  };

  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-background p-4">
      {renderContent()}
    </div>
  );
}
