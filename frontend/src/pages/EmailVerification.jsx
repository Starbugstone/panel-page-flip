import { useState, useEffect } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Loader2, AlertCircle, CheckCircle2, Mail } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { Input } from "@/components/ui/input";

export default function EmailVerification() {
  const [isLoading, setIsLoading] = useState(false);
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [status, setStatus] = useState("");
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();

  // Extract status and message from URL query parameters
  useEffect(() => {
    const params = new URLSearchParams(location.search);
    const statusParam = params.get("status");
    const messageParam = params.get("message");
    
    if (statusParam) {
      setStatus(statusParam);
    }
    
    if (messageParam) {
      setMessage(decodeURIComponent(messageParam));
    }
    
    // Extract email from state if available (passed from login page)
    if (location.state?.email) {
      setEmail(location.state.email);
    }
  }, [location]);

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
      const response = await fetch("/api/email-verification/resend", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ email }),
      });

      const data = await response.json();
      
      toast({
        title: "Verification Email Sent",
        description: data.message || "If your email exists in our system, a verification email has been sent.",
      });
      
      setStatus("resent");
      setMessage("Verification email has been resent. Please check your inbox.");
    } catch (error) {
      console.error("Error resending verification email:", error);
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
