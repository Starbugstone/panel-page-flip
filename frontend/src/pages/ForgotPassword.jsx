import { useState } from "react";
import { Link } from "react-router-dom";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

export default function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [formState, setFormState] = useState("idle");

  const handleSubmit = async (event) => {
    event.preventDefault();
    setFormState("loading");
    try {
      await api.post("/api/forgot-password", { email }, { notifyUnauthorized: false });
    } catch (error) {
      // The same response prevents account enumeration, including server failures.
      logger.warn("Password reset request failed:", error.message);
    }
    setFormState("success");
  };

  return (
    <AuthLayout
      title="Reset Password"
      description={formState === "success" ? "Check your email for reset instructions" : "Enter your email to receive password reset instructions"}
      footer={<Link to="/login" className="text-primary hover:underline">Back to Login</Link>}
    >
      {formState === "success" ? (
        <div role="status" className="space-y-4 text-center">
          <p>If an account exists with the email <strong>{email}</strong>, you'll receive instructions on how to reset your password shortly.</p>
          <p className="text-sm text-muted-foreground">Don't forget to check your spam folder if you don't see the email.</p>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" name="email" type="email" autoComplete="email" placeholder="your@email.com" value={email} onChange={(event) => setEmail(event.target.value)} required />
          </div>
          <Button type="submit" className="w-full" disabled={formState === "loading"}>
            {formState === "loading" ? "Sending..." : "Send Reset Link"}
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
