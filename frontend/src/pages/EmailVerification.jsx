import { useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Label } from "@/components/ui/label";
import { Loader2, Mail } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

function verificationCopy(status, message) {
  if (status === "verification-success") {
    return { title: "Email Verified!", description: message || "Your email has been verified successfully. You can now log in." };
  }
  if (status === "verification-failed") {
    return { title: "Verification Failed", description: message || "Failed to verify your email. The link may be invalid or expired." };
  }
  return { title: "Email Verification", description: message || "Please verify your email address to activate your account." };
}

function emailFromLocation(location) {
  return location.state?.email ?? "";
}

export default function EmailVerification() {
  const [isLoading, setIsLoading] = useState(false);
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
  const initialEmail = emailFromLocation(location);
  const email = typedEmail ?? initialEmail;
  const setEmail = setTypedEmail;

  const handleResendVerification = async (event) => {
    event.preventDefault();
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
      const data = await api.post("/api/email-verification/resend", { email }, { notifyUnauthorized: false });
      
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

  const verified = status === "verification-success";
  const failed = status === "verification-failed";
  const { title, description } = verificationCopy(status, message);

  return (
    <AuthLayout title={title} footer={<Link to="/login" className="text-primary hover:underline">{verified ? "Go to Login" : "Back to Login"}</Link>}>
      <p role={failed ? "alert" : "status"} className="mb-6 text-center text-sm text-muted-foreground">{description}</p>
      {!verified && (
        <form onSubmit={handleResendVerification} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="email">Your Email Address</Label>
            <Input id="email" name="email" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Enter your email" required />
          </div>
          <Button type="submit" disabled={isLoading} className="w-full">
            {isLoading ? <Loader2 aria-hidden="true" className="mr-2 h-4 w-4 animate-spin" /> : <Mail aria-hidden="true" className="mr-2 h-4 w-4" />}
            {isLoading ? "Sending..." : "Resend Verification Email"}
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
