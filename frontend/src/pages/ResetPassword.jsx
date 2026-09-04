import { useState, useEffect } from "react";
import { useParams, Link } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
import { Input } from "@/components/ui/input.jsx";
import { Label } from "@/components/ui/label.jsx";
import { useToast } from "@/hooks/use-toast.js";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { PageLoading } from "@/components/layout/PageLayout";
import { validatePassword } from "@/lib/password-policy";
import { api } from "@/lib/api";

const INVALID_TOKEN_MESSAGE = "Invalid or expired token";
const VALIDATION_ERROR_FALLBACK = "The reset link could not be checked. Please try again.";

/** The new password, twice, with the rules it still fails listed as you type. */
function ResetPasswordForm({
  password, setPassword, confirmPassword, setConfirmPassword, passwordErrors, loading, onSubmit,
}) {
  return (
          <form onSubmit={onSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="password">New Password</Label>
              <Input
                id="password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={12}
              />
              {password && passwordErrors.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  Password must include: {passwordErrors.join(", ")}.
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="confirm-password">Confirm New Password</Label>
              <Input
                id="confirm-password"
                type="password"
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                required
                minLength={12}
              />
            </div>
            <Button
              type="submit"
              className="w-full"
              disabled={loading}
            >
              {loading ? "Resetting..." : "Reset Password"}
            </Button>
          </form>
  );
}

function ResetPasswordStatus({ validation, resetComplete, validationError, onRetry }) {
  if (validation === null) {
    return <AuthLayout title="Validating Reset Link"><PageLoading label="Please wait while we validate your reset link..." /></AuthLayout>;
  }
  if (validation === "error") {
    return (
      <AuthLayout title="Could Not Validate Reset Link" description={validationError}>
        <Button className="w-full" onClick={onRetry}>Try again</Button>
      </AuthLayout>
    );
  }
  if (validation === "invalid") {
    return (
      <AuthLayout title="Invalid Reset Link" description="This password reset link is invalid or has expired.">
        <Button asChild className="w-full"><Link to="/forgot-password">Request New Reset Link</Link></Button>
      </AuthLayout>
    );
  }
  if (resetComplete) {
    return (
      <AuthLayout title="Password Reset Complete" description="Your password has been reset successfully.">
        <p role="status" className="mb-6 text-center">You can now log in with your new password.</p>
        <Button asChild className="w-full"><Link to="/login">Go to Login</Link></Button>
      </AuthLayout>
    );
  }
  return null;
}

export default function ResetPassword() {
  const { token } = useParams();
  return <ResetPasswordPage key={token} token={token} />;
}

function ResetPasswordPage({ token }) {
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [loading, setLoading] = useState(false);
  // null while the check is in flight; otherwise records the validation outcome.
  const [validation, setValidation] = useState(token ? null : "invalid");
  const [attempt, setAttempt] = useState(0);
  const [validationError, setValidationError] = useState("");
  const [resetComplete, setResetComplete] = useState(false);
  
  const { toast } = useToast();
  const passwordErrors = validatePassword(password);

  useEffect(() => {
    if (!token) return undefined;

    let ignore = false;
    api.get(`/api/reset-password/validate/${token}`, { notifyUnauthorized: false })
      .then(() => { if (!ignore) setValidation("valid"); })
      .catch((error) => {
        if (ignore) return;
        const invalidToken = error.status === 400
          && error.data?.message === INVALID_TOKEN_MESSAGE;

        if (invalidToken) {
          setValidation("invalid");
          return;
        }

        setValidationError(error.message || VALIDATION_ERROR_FALLBACK);
        setValidation("error");
      });

    return () => { ignore = true; };
  }, [token, attempt]);

  const retryValidation = () => {
    setValidation(null);
    setAttempt((current) => current + 1);
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    
    // Validate passwords match
    if (password !== confirmPassword) {
      toast({
        title: "Passwords Don't Match",
        description: "Please make sure your passwords match.",
        variant: "destructive",
      });
      return;
    }

    if (passwordErrors.length > 0) {
      toast({
        title: "Password Too Weak",
        description: `Password must include: ${passwordErrors.join(", ")}.`,
        variant: "destructive",
      });
      return;
    }

    setLoading(true);

    try {
      await api.post(`/api/reset-password/reset/${token}`, { password }, { notifyUnauthorized: false });

      setResetComplete(true);
      toast({
        title: "Password Reset Successful",
        description: "Your password has been reset successfully. You can now log in with your new password.",
      });
      
    } catch (error) {
      toast({
        title: "Reset Failed",
        description: error.message || "An error occurred. Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  if (validation !== "valid" || resetComplete) {
    return <ResetPasswordStatus validation={validation} resetComplete={resetComplete} validationError={validationError} onRetry={retryValidation} />;
  }

  return (
    <AuthLayout title="Reset Your Password" description="Enter your new password below">
      <ResetPasswordForm
        password={password}
        setPassword={setPassword}
        confirmPassword={confirmPassword}
        setConfirmPassword={setConfirmPassword}
        passwordErrors={passwordErrors}
        loading={loading}
        onSubmit={handleSubmit}
      />
    </AuthLayout>
  );
}
