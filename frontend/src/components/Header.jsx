
import { Link } from "react-router-dom";
import { ThemeToggle } from "./ThemeToggle.jsx";
import { useLocation } from "react-router-dom";
import { Button } from "./ui/button.jsx";
import { BookOpen, Upload, Settings, User, Cloud } from "lucide-react";
import { useEffect, useState } from "react";

export function Header({ isLoggedIn, onLogout, isAdmin }) {
  const location = useLocation();
  const isReaderPage = location.pathname.includes("/read/");
  const [isFullscreen, setIsFullscreen] = useState(false);
  
  // Listen for fullscreen changes
  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!document.fullscreenElement);
    };
    
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => {
      document.removeEventListener('fullscreenchange', handleFullscreenChange);
    };
  }, []);

  if (isReaderPage) {
    // In fullscreen mode, use fixed position with hover effect
    // In normal mode, use relative position with minimal height
    if (isFullscreen) {
      return (
        <header className="fixed top-0 left-0 right-0 z-[60] bg-gradient-to-b from-background/90 to-transparent p-2 opacity-0 hover:opacity-100 transition-opacity duration-300">
          <div className="flex justify-between items-center">
            <Link to="/dashboard" className="text-foreground hover:text-comic-purple text-sm">
              Back to Library
            </Link>
            <ThemeToggle />
          </div>
        </header>
      );
    } else {
      // Compact header for normal mode with relative positioning
      return (
        <header className="relative w-full bg-background border-b border-border py-1 px-4 z-[60]">
          <div className="flex justify-between items-center">
            <Link to="/dashboard" className="text-foreground hover:text-comic-purple text-sm">
              Back to Library
            </Link>
            <ThemeToggle />
          </div>
        </header>
      );
    }
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
              <Link to="/dashboard" className={`${location.pathname === "/dashboard" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                My Comics
              </Link>
              <Link to="/upload" className={`${location.pathname === "/upload" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden md:inline">Upload Comic</span>
                <Upload className="inline md:hidden h-5 w-5" />
              </Link>
              <Link to="/dropbox-sync" className={`${location.pathname === "/dropbox-sync" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden md:inline">Dropbox Sync</span>
                <Cloud className="inline md:hidden h-5 w-5" />
              </Link>
              {isAdmin && (
                <Link to="/admin" className={`${location.pathname === "/admin" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                  <span className="hidden md:inline">Admin Panel</span>
                  <Settings className="inline md:hidden h-5 w-5" />
                </Link>
              )}
              <div className="flex items-center gap-4">
                <Button variant="ghost" onClick={onLogout} className="hidden md:flex gap-2">
                  <User size={16} />
                  Logout
                </Button>
                <Button variant="ghost" onClick={onLogout} className="md:hidden">
                  <User size={16} />
                </Button>
              </div>
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
