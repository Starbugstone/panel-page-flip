import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Loader2, AlertCircle, CheckCircle2, BookOpen } from "lucide-react";
import { useToast } from "@/hooks/use-toast";

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
      const acceptShare = async () => {
        setIsLoading(true);
        setError(null);
        setSuccessMessage(null);
        setAcceptedComicTitle(null);
        setAcceptedComicId(null);

        try {
          const response = await fetch(`/api/share/accept/${token}`, {
            method: "POST",
            headers: {
              // Assuming cookie-based authentication, no explicit Authorization header needed
              "Content-Type": "application/json",
            },
          });

          const data = await response.json();

          if (!response.ok) {
            throw new Error(data.error || `Failed to accept share. Status: ${response.status}`);
          }
          
          // Store the comic details for display and navigation
          setAcceptedComicTitle(data.comic?.title || "the comic");
          setAcceptedComicId(data.comic?.id);
          setSuccessMessage("Comic successfully added to your library!");
          
          // Show a toast notification that will persist even after navigation
          toast({
            title: "Comic Added to Library",
            description: `${data.title || "Comic"} has been added to your collection.`,
            duration: 5000
          });

        } catch (err) {
          setError(err.message || "An unexpected error occurred while accepting the share.");
        } finally {
          setIsLoading(false);
        }
      };

      acceptShare();
    } else if (!token) {
        setError("No share token provided.");
        setIsLoading(false);
    }
  }, [token, auth.isAuthenticated, auth.loading]); // Dependency array

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
          <AlertTitle>Error Accepting Share</AlertTitle>
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

    return null; // Should not be reached if logic is correct
  };

  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-background p-4">
      {renderContent()}
    </div>
  );
}
