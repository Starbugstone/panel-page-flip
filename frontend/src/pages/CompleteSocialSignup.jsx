import { useEffect, useState } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { PageLoading } from "@/components/layout/PageLayout";
import { Link, useNavigate } from "react-router-dom";
import { api } from "@/lib/api";
import { useAuth } from "@/hooks/use-auth";
import { useToast } from "@/hooks/use-toast";
import { stripUsernamePrefix, validateUsername } from "@/lib/sharing";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const providerName = (provider) => provider === "google" ? "Google" : provider;

export default function CompleteSocialSignup() {
  const navigate = useNavigate();
  const { checkAuth } = useAuth();
  const { toast } = useToast();
  const [pending, setPending] = useState(null);
  const [username, setUsername] = useState("");
  const [agreeTerms, setAgreeTerms] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [expired, setExpired] = useState(false);
  const usernameError = username === "" ? null : validateUsername(username);

  useEffect(() => {
    let active = true;
    api.get("/api/auth/oauth/pending", { notifyUnauthorized: false })
      .then((data) => {
        if (!active) return;
        setPending(data);
        setUsername(data.suggestedUsername || "");
      })
      .catch(() => { if (active) setExpired(true); })
      .finally(() => { if (active) setLoading(false); });

    return () => { active = false; };
  }, []);

  const completeSignup = async (event) => {
    event.preventDefault();
    if (!agreeTerms || usernameError) return;
    setSaving(true);
    try {
      const data = await api.post(
        "/api/auth/oauth/complete-registration",
        { username, agreeTerms },
        { notifyUnauthorized: false },
      );
      if (data.requiresVerification) {
        navigate("/email-verification", { state: { email: data.email } });
        return;
      }

      await checkAuth();
      toast({ title: "Account created", description: "You are now signed in." });
      navigate(data.redirect || "/dashboard", { replace: true });
    } catch (error) {
      const suggestion = error.data?.suggestion;
      if (suggestion) setUsername(suggestion);
      toast({
        title: "Could not complete signup",
        description: error.message,
        variant: "destructive",
      });
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <AuthLayout title="Complete social signup"><PageLoading label="Loading social signup…" /></AuthLayout>;
  if (expired || !pending) {
    return (
      <AuthLayout title="This social signup has expired" description="Start again so the provider can confirm your account.">
        <Button asChild className="mt-6"><Link to="/login">Back to login</Link></Button>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title="Complete social signup" description={<>Continue with {providerName(pending.provider)} as {pending.email}</>}>
        <form onSubmit={completeSignup} className="mt-8 space-y-5">
          <div className="space-y-2">
            <Label htmlFor="social-username">Username</Label>
            <Input
              id="social-username"
              value={username}
              onChange={(event) => setUsername(stripUsernamePrefix(event.target.value))}
              autoComplete="username"
            />
            <p className="text-xs text-muted-foreground">This public name is used for sharing; your provider email stays private.</p>
            {usernameError && <p className="text-xs text-destructive">{usernameError}</p>}
          </div>

          <div className="flex items-start gap-2">
            <Checkbox
              id="social-signup-terms"
              checked={agreeTerms}
              onCheckedChange={(checked) => setAgreeTerms(checked === true)}
              required
            />
            <Label htmlFor="social-signup-terms" className="text-xs font-normal leading-5 text-muted-foreground">
              I agree to the <Link className="underline hover:text-foreground" to="/terms">Terms of Service</Link>
              {" "}and acknowledge the <Link className="underline hover:text-foreground" to="/privacy">Privacy Policy</Link>.
            </Label>
          </div>

          <Button className="w-full" disabled={!agreeTerms || Boolean(usernameError) || saving}>
            {saving ? "Creating account…" : "Create account and continue"}
          </Button>
        </form>
    </AuthLayout>
  );
}
