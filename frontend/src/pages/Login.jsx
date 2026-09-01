import { useCallback, useEffect, useState } from "react";
import { useNavigate, useSearchParams, Link } from "react-router-dom";
import { BookOpen } from "lucide-react";
import { Button } from "@/components/ui/button.jsx";
import { Input } from "@/components/ui/input.jsx";
import { Label } from "@/components/ui/label.jsx";
import { Checkbox } from "@/components/ui/checkbox.jsx";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";
import { useToast } from "@/hooks/use-toast.js";
import { useAuth } from "@/hooks/use-auth.jsx";
import { validatePassword } from "@/lib/password-policy";
import { stripUsernamePrefix, validateUsername } from "@/lib/sharing";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

const isLocalRedirect = (path) => typeof path === "string"
  && path.startsWith("/")
  && !path.startsWith("//")
  && !path.includes("\\")
  && !Array.from(path).some((character) => {
    const code = character.charCodeAt(0);
    return code < 32 || code === 127;
  });

export default function Login() {
  // Login form state
  const [loginEmail, setLoginEmail] = useState("");
  const [loginPassword, setLoginPassword] = useState("");
  const [loginLoading, setLoginLoading] = useState(false);
  
  // Registration form state
  const [registerEmail, setRegisterEmail] = useState("");
  const [registerPassword, setRegisterPassword] = useState("");
  const [registerName, setRegisterName] = useState("");
  // Suggested rather than demanded. A public handle is the one field nobody
  // arrives at a signup form having decided on, and an empty box next to
  // "required" is where registrations stall.
  const [registerUsername, setRegisterUsername] = useState("");
  const [isSuggestingUsername, setIsSuggestingUsername] = useState(false);
  const [agreeTerms, setAgreeTerms] = useState(false);
  const [registerLoading, setRegisterLoading] = useState(false);
  const [socialProviders, setSocialProviders] = useState({});
  
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const defaultTab = searchParams.get("signup") ? "signup" : "login";
  const requestedRedirect = searchParams.get("redirect");
  const oauthError = searchParams.get("oauth_error");
  const redirectPath = isLocalRedirect(requestedRedirect) ? requestedRedirect : "/dashboard";
  const { toast } = useToast();
  const { login, register } = useAuth();
  const registerPasswordErrors = validatePassword(registerPassword);
  const registerUsernameError = registerUsername === "" ? null : validateUsername(registerUsername);

  useEffect(() => {
    let active = true;
    api.get("/api/auth/providers", { notifyUnauthorized: false })
      .then((data) => { if (active) setSocialProviders(data || {}); })
      .catch((error) => logger.warn("Could not load social sign-in providers:", error.message));

    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!oauthError) return;

    const messages = {
      account_exists: "An account already exists with this email. Sign in with your existing method, then connect Google from Settings.",
      verification_required: "Verify the email on this account before signing in.",
      expired: "This social sign-in attempt expired. Please try again.",
      invalid_state: "Social sign-in could not be verified. Please try again.",
      cancelled: "Social sign-in was cancelled or not completed.",
      unavailable: "That social sign-in provider is not available on this installation.",
      failed: "Social sign-in could not be completed. Please try again.",
    };
    toast({
      title: "Social sign-in unsuccessful",
      description: messages[oauthError] || "Social sign-in could not be completed. Please try again.",
      variant: "destructive",
    });
  }, [oauthError, toast]);

  const fetchSuggestion = useCallback(
    () => api.get("/api/users/username-suggestion")
      .then((data) => data.username || "")
      .catch((error) => {
        // Not fatal: the field is editable, and registration fills one in
        // server-side for anybody who leaves it blank.
        logger.warn("Could not suggest a username:", error.message);

        return null;
      }),
    []
  );

  // One suggestion when the page opens, so the field is never an empty box next
  // to the word "username" — which is where a signup stalls.
  useEffect(() => {
    let ignore = false;

    fetchSuggestion().then((username) => {
      // Only into an empty field. Somebody who started typing before this
      // request came back has chosen a name, and overwriting it as they type is
      // the field fighting them.
      if (!ignore && username) {
        setRegisterUsername((current) => current || username);
      }
    });

    return () => { ignore = true; };
  }, [fetchSuggestion]);

  const suggestUsername = async () => {
    setIsSuggestingUsername(true);
    const username = await fetchSuggestion();
    if (username) setRegisterUsername(username);
    setIsSuggestingUsername(false);
  };

  const socialLogin = (provider) => {
    window.location.assign(`/api/auth/oauth/${provider}/start?redirect=${encodeURIComponent(redirectPath)}`);
  };

  const handleLoginSubmit = async (event) => {
    event.preventDefault();
    setLoginLoading(true);

    try {
      await login(loginEmail, loginPassword);
      
      toast({
        title: "Success",
        description: "You have successfully logged in!",
      });
      navigate(redirectPath);
    } catch (error) {
      // Check if the error is due to email verification
      if (error.cause?.requiresVerification) {
        // Redirect to email verification page with the email
        navigate("/email-verification", { state: { email: error.cause.email } });
        return;
      }
      
      toast({
        title: "Login Failed",
        description: error.message || "Invalid credentials. Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoginLoading(false);
    }
  };
  
  const handleRegisterSubmit = async (event) => {
    event.preventDefault();
    setRegisterLoading(true);

    try {
      if (registerPasswordErrors.length > 0) {
        throw new Error(`Password must include: ${registerPasswordErrors.join(", ")}.`);
      }

      if (!agreeTerms) {
        throw new Error("You must agree to the Terms of Service and acknowledge the Privacy Policy.");
      }

      if (registerUsernameError) {
        throw new Error(registerUsernameError);
      }

      await register(registerEmail, registerPassword, registerName, agreeTerms, registerUsername);
      
      toast({
        title: "Success",
        description: "Account created successfully! Please verify your email before logging in.",
      });
      
      // Reset form and switch to login tab
      setRegisterEmail("");
      setRegisterPassword("");
      setRegisterName("");
      setAgreeTerms(false);
      
      // Switch to login tab
      navigate(redirectPath === "/dashboard" ? "/login" : `/login?redirect=${encodeURIComponent(redirectPath)}`);
    } catch (error) {
      toast({
        title: "Registration Failed",
        description: error.message || "Could not create account. Please try again.",
        variant: "destructive",
      });
    } finally {
      setRegisterLoading(false);
    }
  };

  return (
    <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full space-y-6">
        <div className="space-y-8 bg-card p-6 sm:p-8 rounded-lg shadow-lg">
          <div className="text-center">
            <BookOpen className="h-12 w-12 text-comic-purple mx-auto" />
            <h1 className="mt-4 font-comic text-2xl">Welcome to Panel Page Flip</h1>
            <p className="mt-2 text-muted-foreground">Access your comic collection</p>
          </div>

          {socialProviders.google && (
            <div className="space-y-4">
              <Button type="button" variant="outline" className="w-full" onClick={() => socialLogin("google")}>
                Continue with Google
              </Button>
              <div className="flex items-center gap-3" aria-label="or">
                <div className="h-px flex-1 bg-border" />
                <span className="text-xs uppercase tracking-wide text-muted-foreground">or</span>
                <div className="h-px flex-1 bg-border" />
              </div>
            </div>
          )}

          <Tabs defaultValue={defaultTab} className="w-full">
            <TabsList className="grid w-full grid-cols-2">
              <TabsTrigger value="login">Login</TabsTrigger>
              <TabsTrigger value="signup">Sign up</TabsTrigger>
            </TabsList>
            <TabsContent value="login">
              <form onSubmit={handleLoginSubmit} className="space-y-4 mt-6">
                <div className="space-y-2">
                  <Label htmlFor="login-email">Email</Label>
                  <Input 
                    id="login-email" 
                    type="email" 
                    placeholder="your@email.com" 
                    value={loginEmail}
                    onChange={(e) => setLoginEmail(e.target.value)}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <div className="flex justify-between">
                    <Label htmlFor="login-password">Password</Label>
                    <Link to="/forgot-password" className="text-xs text-comic-purple hover:underline">
                      Forgot password?
                    </Link>
                  </div>
                  <Input 
                    id="login-password" 
                    type="password" 
                    value={loginPassword}
                    onChange={(e) => setLoginPassword(e.target.value)}
                    required
                  />
                </div>
                <Button 
                  type="submit" 
                  className="w-full bg-comic-purple hover:bg-comic-purple-dark"
                  disabled={loginLoading}
                >
                  {loginLoading ? "Logging in..." : "Log in"}
                </Button>
              </form>
            </TabsContent>
            <TabsContent value="signup">
              <form onSubmit={handleRegisterSubmit} className="space-y-4 mt-6">
                <div className="space-y-2">
                  <Label htmlFor="signup-name">Name</Label>
                  <Input 
                    id="signup-name" 
                    type="text" 
                    placeholder="Your Name" 
                    value={registerName}
                    onChange={(e) => setRegisterName(e.target.value)}
                    required 
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-username">Username</Label>
                  <div className="flex gap-2">
                    <Input
                      id="signup-username"
                      type="text"
                      placeholder="SilverOtter4821"
                      value={registerUsername}
                      onChange={(e) => setRegisterUsername(stripUsernamePrefix(e.target.value))}
                    />
                    <Button
                      type="button"
                      variant="outline"
                      onClick={suggestUsername}
                      disabled={isSuggestingUsername}
                    >
                      Generate another
                    </Button>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    This is how other people find you when they want to share a comic. Your email
                    address is never shown to them.
                  </p>
                  {registerUsernameError && (
                    <p className="text-xs text-destructive">{registerUsernameError}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-email">Email</Label>
                  <Input 
                    id="signup-email" 
                    type="email" 
                    placeholder="your@email.com" 
                    value={registerEmail}
                    onChange={(e) => setRegisterEmail(e.target.value)}
                    required 
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-password">Password</Label>
                  <Input 
                    id="signup-password" 
                    type="password" 
                    value={registerPassword}
                    onChange={(e) => setRegisterPassword(e.target.value)}
                    minLength={12}
                    required 
                  />
                  {registerPassword && registerPasswordErrors.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                      Password must include: {registerPasswordErrors.join(", ")}.
                    </p>
                  )}
                </div>
                <div className="flex items-start gap-2">
                  <Checkbox
                    id="signup-terms"
                    checked={agreeTerms}
                    onCheckedChange={(checked) => setAgreeTerms(checked === true)}
                    required
                  />
                  <Label htmlFor="signup-terms" className="text-xs font-normal leading-5 text-muted-foreground">
                    I agree to the{" "}
                    <Link className="underline hover:text-foreground" to="/terms">Terms of Service</Link>
                    {" "}and acknowledge the{" "}
                    <Link className="underline hover:text-foreground" to="/privacy">Privacy Policy</Link>.
                  </Label>
                </div>
                <Button 
                  type="submit" 
                  className="w-full bg-comic-purple hover:bg-comic-purple-dark"
                  disabled={registerLoading || !agreeTerms}
                >
                  {registerLoading ? "Creating account..." : "Create account"}
                </Button>
              </form>
            </TabsContent>
          </Tabs>
        </div>

        <div className="bg-card p-6 sm:p-8 rounded-lg shadow-lg space-y-2 text-center text-sm text-muted-foreground">
          <p>
            Panel Page Flip is your personal multi-format comic library on the web. Upload your comics,
            organize them with tags, and read page by page with your progress saved
            so you can pick up right where you left off.
          </p>
          <p>
            Keep your collection tidy with search and reading filters, or sync comic sources
            from Dropbox when you prefer. When a friend should see a book too, share
            it directly by username, U- code, or email, or send a private C- or G- code.
          </p>
        </div>
      </div>
    </div>
  );
}
