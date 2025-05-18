import { useState } from "react";
import { Link } from "react-router-dom";
import { BookOpen } from "lucide-react";
import { Button } from "@/components/ui/button.jsx";
import { Input } from "@/components/ui/input.jsx";
import { Label } from "@/components/ui/label.jsx";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card.jsx";

export default function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [formState, setFormState] = useState("idle"); // idle, loading, success

  const handleSubmit = (event) => {
    event.preventDefault();
    
    // Set loading state
    setFormState("loading");
    
    // Simulate API call with setTimeout
    setTimeout(() => {
      // After "API call", set success state
      setFormState("success");
      
      // Also make the actual API call in the background
      fetch('/api/forgot-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email }),
      }).catch(error => {
        console.error('Error sending password reset request:', error);
      });
    }, 1500); // Simulate network delay
  };

  // Render different UI based on form state
  if (formState === "success") {
    return (
      <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
        <Card className="max-w-md w-full">
          <CardHeader className="text-center">
            <BookOpen className="h-12 w-12 text-comic-purple mx-auto" />
            <CardTitle className="mt-4 font-comic text-2xl">Reset Password</CardTitle>
            <CardDescription>
              Check your email for reset instructions
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-center space-y-4">
              <p>
                If an account exists with the email <strong>{email}</strong>, you'll receive 
                instructions on how to reset your password shortly.
              </p>
              <p className="text-sm text-muted-foreground">
                Don't forget to check your spam folder if you don't see the email.
              </p>
            </div>
          </CardContent>
          <CardFooter className="flex justify-center">
            <Link to="/login" className="text-sm text-comic-purple hover:underline">
              Back to Login
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
          <CardTitle className="mt-4 font-comic text-2xl">Reset Password</CardTitle>
          <CardDescription>
            Enter your email to receive password reset instructions
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input 
                id="email" 
                type="email" 
                placeholder="your@email.com" 
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>
            <Button 
              type="submit" 
              className="w-full bg-comic-purple hover:bg-comic-purple-dark"
              disabled={formState === "loading"}
            >
              {formState === "loading" ? "Sending..." : "Send Reset Link"}
            </Button>
          </form>
        </CardContent>
        <CardFooter className="flex justify-center">
          <Link to="/login" className="text-sm text-comic-purple hover:underline">
            Back to Login
          </Link>
        </CardFooter>
      </Card>
    </div>
  );
}
