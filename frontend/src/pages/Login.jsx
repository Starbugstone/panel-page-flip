import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { BookOpen } from "lucide-react";
import { Button } from "@/components/ui/button.jsx";
import { Input } from "@/components/ui/input.jsx";
import { Label } from "@/components/ui/label.jsx";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";
import { useToast } from "@/hooks/use-toast.js";
import { useAuth } from "@/hooks/use-auth.js";

export default function Login() {
  // Login form state
  const [loginEmail, setLoginEmail] = useState("");
  const [loginPassword, setLoginPassword] = useState("");
  const [loginLoading, setLoginLoading] = useState(false);
  
  // Registration form state
  const [registerEmail, setRegisterEmail] = useState("");
  const [registerPassword, setRegisterPassword] = useState("");
  const [registerName, setRegisterName] = useState("");
  const [registerLoading, setRegisterLoading] = useState(false);
  
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const defaultTab = searchParams.get("signup") ? "signup" : "login";
  const { toast } = useToast();
  const { login, register } = useAuth();

  const handleLoginSubmit = async (event) => {
    event.preventDefault();
    setLoginLoading(true);

    try {
      await login(loginEmail, loginPassword);
      
      toast({
        title: "Success",
        description: "You have successfully logged in!",
      });
      navigate("/dashboard");
    } catch (error) {
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
      await register(registerEmail, registerPassword);
      
      toast({
        title: "Success",
        description: "Account created successfully! You can now log in.",
      });
      
      // Reset form and switch to login tab
      setRegisterEmail("");
      setRegisterPassword("");
      setRegisterName("");
      
      // Switch to login tab
      navigate("/login");
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
      <div className="max-w-md w-full space-y-8 bg-card p-6 sm:p-8 rounded-lg shadow-lg">
        <div className="text-center">
          <BookOpen className="h-12 w-12 text-comic-purple mx-auto" />
          <h1 className="mt-4 font-comic text-2xl">Welcome to Comic Reader</h1>
          <p className="mt-2 text-muted-foreground">Access your comic collection</p>
        </div>

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
                  <a href="#" className="text-xs text-comic-purple hover:underline">
                    Forgot password?
                  </a>
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
                  required 
                />
              </div>
              <Button 
                type="submit" 
                className="w-full bg-comic-purple hover:bg-comic-purple-dark"
                disabled={registerLoading}
              >
                {registerLoading ? "Creating account..." : "Create account"}
              </Button>
              <p className="text-xs text-center text-muted-foreground">
                By signing up, you agree to our Terms of Service and Privacy Policy.
              </p>
            </form>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
}
