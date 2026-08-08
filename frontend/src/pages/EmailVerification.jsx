import { useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Loader2, AlertCircle, CheckCircle2, Mail } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

export default function EmailVerification() {
  const [isLoading, setIsLoading] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();

  // The URL already holds the outcome the backend redirected here with, and the
  // login page passes the address it knows. Reading them during render keeps
  // one source of truth; copying them into state from an effect meant the first
  // paint always showed the neutral "please verify" card before correcting
  // itself. Resending, and typing an address, override them from that point on
  // — tagged with the URL they were entered against so a later redirect with a
  // fresh result is not masked by a stale override.
  const params = new URLSearchParams(location.search);
  const urlStatus = params.get("status") || "";
  // Not decoded again: URLSearchParams.get has already done it, and a second
  // pass throws URIError on a literal % — during render, so the page would not
  // mount at all.
  const urlMessage = params.get("message") ?? "";

  const [resendResult, setResendResult] = useState(null);
  const [typedEmail, setTypedEmail] = useState(null);

  const current = resendResult?.forSearch === location.search ? resendResult : null;
  const status = current?.status ?? urlStatus;
  const message = current?.message ?? urlMessage;
  const email = typedEmail ?? location.state?.email ?? "";
  const setEmail = setTypedEmail;

  const handleResendVerification = async () => {
    if (!email) {
      toast({
        title: "Email Required",
        description: "Please enter your email address.",
        variant: "destructive",
      });
      return;
    }

    setIsLoading(true);
    try {
      const data = await api.post("/api/email-verification/resend", { email });
      
      toast({
        title: "Verification Email Sent",
        description: data.message || "If your email exists in our system, a verification email has been sent.",
      });
      
      setResendResult({
        forSearch: location.search,
        status: "resent",
        message: "Verification email has been resent. Please check your inbox.",
      });
    } catch (error) {
      logger.error("Error resending verification email:", error);
      toast({
        title: "Error",
        description: "Failed to resend verification email. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const renderContent = () => {
    if (isLoading) {
      return (
        <div className="flex flex-col items-center justify-center text-center">
          <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
          <p className="text-lg text-muted-foreground">Processing, please wait...</p>
        </div>
      );
    }

    if (status === "verification-success") {
      return (
        <Alert variant="default" className="max-w-md text-center border-green-500">
          <CheckCircle2 className="h-5 w-5 text-green-600" />
          <AlertTitle className="text-green-700">Email Verified!</AlertTitle>
          <AlertDescription className="mb-4">
            {message || "Your email has been verified successfully. You can now log in."}
          </AlertDescription>
          <Button 
            onClick={() => navigate("/login")} 
            className="mt-4">
            Go to Login
          </Button>
        </Alert>
      );
    }

    if (status === "verification-failed") {
      return (
        <Alert variant="destructive" className="max-w-md text-center">
          <AlertCircle className="h-5 w-5" />
          <AlertTitle>Verification Failed</AlertTitle>
          <AlertDescription className="mb-4">
            {message || "Failed to verify your email. The link may be invalid or expired."}
          </AlertDescription>
          <div className="mt-4 space-y-4">
            <div className="flex flex-col space-y-2">
              <label htmlFor="email" className="text-sm font-medium">
                Your Email Address
              </label>
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="Enter your email"
                className="w-full"
              />
            </div>
            <Button 
              onClick={handleResendVerification} 
              disabled={isLoading}
              className="w-full">
              {isLoading ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Sending...
                </>
              ) : (
                <>
                  <Mail className="mr-2 h-4 w-4" />
                  Resend Verification Email
                </>
              )}
            </Button>
            <Button 
              variant="outline" 
              onClick={() => navigate("/login")} 
              className="w-full">
              Back to Login
            </Button>
          </div>
        </Alert>
      );
    }

    // Default state or "resent" status
    return (
      <Alert variant="default" className="max-w-md text-center">
        <Mail className="h-5 w-5" />
        <AlertTitle>Email Verification</AlertTitle>
        <AlertDescription className="mb-4">
          {message || "Please verify your email address to activate your account."}
        </AlertDescription>
        <div className="mt-4 space-y-4">
          <div className="flex flex-col space-y-2">
            <label htmlFor="email" className="text-sm font-medium">
              Your Email Address
            </label>
            <Input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="Enter your email"
              className="w-full"
            />
          </div>
          <Button 
            onClick={handleResendVerification} 
            disabled={isLoading}
            className="w-full">
            {isLoading ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Sending...
              </>
            ) : (
              <>
                <Mail className="mr-2 h-4 w-4" />
                Resend Verification Email
              </>
            )}
          </Button>
          <Button 
            variant="outline" 
            onClick={() => navigate("/login")} 
            className="w-full">
            Back to Login
          </Button>
        </div>
      </Alert>
    );
  };

  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-background p-4">
      <div className="w-full max-w-md">
        {renderContent()}
      </div>
    </div>
  );
}
