import { useState, useEffect } from "react";
import { useNavigate, useParams, Link } from "react-router-dom";
import { BookOpen } from "lucide-react";
import { Button } from "@/components/ui/button.jsx";
import { Input } from "@/components/ui/input.jsx";
import { Label } from "@/components/ui/label.jsx";
import { useToast } from "@/hooks/use-toast.js";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card.jsx";

export default function ResetPassword() {
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [validatingToken, setValidatingToken] = useState(true);
  const [tokenValid, setTokenValid] = useState(false);
  const [resetComplete, setResetComplete] = useState(false);
  
  const { token } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  // Validate token on component mount
  useEffect(() => {
    const validateToken = async () => {
      try {
        const response = await fetch(`/api/reset-password/validate/${token}`, {
          method: 'GET',
        });

        if (response.ok) {
          setTokenValid(true);
        } else {
          setTokenValid(false);
          toast({
            title: "Invalid or Expired Link",
            description: "This password reset link is invalid or has expired.",
            variant: "destructive",
          });
        }
      } catch (error) {
        setTokenValid(false);
        toast({
          title: "Validation Error",
          description: "Could not validate reset token. Please try again.",
          variant: "destructive",
        });
      } finally {
        setValidatingToken(false);
      }
    };

    if (token) {
      validateToken();
    } else {
      setValidatingToken(false);
      setTokenValid(false);
    }
  }, [token, toast]);

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

    setLoading(true);

    try {
      const response = await fetch(`/api/reset-password/reset/${token}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ password }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to reset password');
      }

      setResetComplete(true);
      toast({
        title: "Password Reset Successful",
        description: "Your password has been reset successfully. You can now log in with your new password.",
      });
      
      // Redirect to login page after 2 seconds
      setTimeout(() => {
        navigate('/login');
      }, 2000);
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

  if (validatingToken) {
    return (
      <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
        <Card className="max-w-md w-full text-center p-6">
          <CardHeader>
            <CardTitle>Validating Reset Link</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="animate-pulse">Please wait while we validate your reset link...</div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!tokenValid) {
    return (
      <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
        <Card className="max-w-md w-full">
          <CardHeader className="text-center">
            <BookOpen className="h-12 w-12 text-comic-purple mx-auto" />
            <CardTitle className="mt-4 font-comic text-2xl">Invalid Reset Link</CardTitle>
            <CardDescription>
              This password reset link is invalid or has expired.
            </CardDescription>
          </CardHeader>
          <CardFooter className="flex justify-center">
            <Link 
              to="/forgot-password"
              className="text-comic-purple hover:underline"
            >
              Request New Reset Link
            </Link>
          </CardFooter>
        </Card>
      </div>
    );
  }

  if (resetComplete) {
    return (
      <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
        <Card className="max-w-md w-full">
          <CardHeader className="text-center">
            <BookOpen className="h-12 w-12 text-comic-purple mx-auto" />
            <CardTitle className="mt-4 font-comic text-2xl">Password Reset Complete</CardTitle>
            <CardDescription>
              Your password has been reset successfully.
            </CardDescription>
          </CardHeader>
          <CardContent className="text-center">
            <p>You can now log in with your new password.</p>
          </CardContent>
          <CardFooter className="flex justify-center">
            <Link 
              to="/login"
              className="text-comic-purple hover:underline"
            >
              Go to Login
            </Link>
          </CardFooter>
        </Card>
      </div>
    );
  }

  return (
    <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
      <Card className="max-w-md w-full">
        <CardHeader className="text-center">
          <BookOpen className="h-12 w-12 text-comic-purple mx-auto" />
          <CardTitle className="mt-4 font-comic text-2xl">Reset Your Password</CardTitle>
          <CardDescription>
            Enter your new password below
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="password">New Password</Label>
              <Input 
                id="password" 
                type="password" 
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="confirm-password">Confirm New Password</Label>
              <Input 
                id="confirm-password" 
                type="password" 
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                required
                minLength={8}
              />
            </div>
            <Button 
              type="submit" 
              className="w-full bg-comic-purple hover:bg-comic-purple-dark"
              disabled={loading}
            >
              {loading ? "Resetting..." : "Reset Password"}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
