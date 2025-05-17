
import { Link } from "react-router-dom";
import { ThemeToggle } from "./ThemeToggle";
import { useLocation } from "react-router-dom";
import { Button } from "./ui/button";
import { BookOpen } from "lucide-react";

interface HeaderProps {
  isLoggedIn: boolean;
  onLogout?: () => void;
}

export function Header({ isLoggedIn, onLogout }: HeaderProps) {
  const location = useLocation();
  const isReaderPage = location.pathname.includes("/read/");

  if (isReaderPage) {
    return (
      <header className="fixed top-0 left-0 right-0 z-50 bg-gradient-to-b from-background/90 to-transparent p-4 opacity-0 hover:opacity-100 transition-opacity duration-300">
        <div className="flex justify-between items-center">
          <Link to="/dashboard" className="text-foreground hover:text-comic-purple">
            Back to Library
          </Link>
          <ThemeToggle />
        </div>
      </header>
    );
  }

  return (
    <header className="border-b">
      <div className="container flex h-16 items-center justify-between px-4 md:px-6">
        <Link to="/" className="flex items-center gap-2">
          <BookOpen className="h-6 w-6 text-comic-purple" />
          <span className="font-comic text-2xl">ComicReader</span>
        </Link>
        <nav className="flex gap-4 items-center">
          {isLoggedIn ? (
            <>
              <Link to="/dashboard" className="text-foreground hover:text-comic-purple">
                My Comics
              </Link>
              <Button variant="ghost" onClick={onLogout}>
                Logout
              </Button>
            </>
          ) : (
            <Link 
              to="/login" 
              className={`${location.pathname === "/login" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
            >
              Login
            </Link>
          )}
          <ThemeToggle />
        </nav>
      </div>
    </header>
  );
}
