import { useEffect, useState } from "react";
import { BookOpen } from "lucide-react";
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

  if (loading) return <div className="container mx-auto max-w-md px-4 py-16 text-center">Loading social signup…</div>;
  if (expired || !pending) {
    return (
      <div className="container mx-auto max-w-md px-4 py-16 text-center">
        <h1 className="font-comic text-2xl">This social signup has expired</h1>
        <p className="mt-3 text-muted-foreground">Start again so the provider can confirm your account.</p>
        <Button asChild className="mt-6"><Link to="/login">Back to login</Link></Button>
      </div>
    );
  }

  return (
    <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
      <div className="w-full max-w-md rounded-lg bg-card p-6 shadow-lg sm:p-8">
        <div className="text-center">
          <BookOpen className="mx-auto h-12 w-12 text-comic-purple" />
          <h1 className="mt-4 font-comic text-2xl">Complete social signup</h1>
          <p className="mt-2 text-muted-foreground">
            Continue with {providerName(pending.provider)} as {pending.email}
          </p>
        </div>

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

          <Button className="w-full bg-comic-purple hover:bg-comic-purple-dark" disabled={!agreeTerms || Boolean(usernameError) || saving}>
            {saving ? "Creating account…" : "Create account and continue"}
          </Button>
        </form>
      </div>
    </div>
  );
}
