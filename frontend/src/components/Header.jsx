
import { Link } from "react-router-dom";
import { ThemeToggle } from "./ThemeToggle.jsx";
import { useLocation } from "react-router-dom";
import { SignedInNav } from "./HeaderSignedInNav.jsx";
import { BookOpen } from "lucide-react";
import { useEffect, useState } from "react";
import { PAGE_LAYER_CLASSES } from "@/lib/overlay-layers.js";
import { useSharing } from "@/hooks/use-sharing.jsx";

export function Header({ isLoggedIn, onLogout, isAdmin }) {
  const location = useLocation();
  const inBulkUpload = location.pathname === "/upload/bulk" || location.pathname.startsWith("/upload/bulk/");
  const isReaderPage = location.pathname.includes("/read/");
  const [isFullscreen, setIsFullscreen] = useState(false);
  const { summary } = useSharing();
  const pendingInvitations = summary.pendingInvitations;
  
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
        <header className="reader-header-fullscreen fixed left-0 right-0 top-0 z-[60] bg-gradient-to-b from-background/90 to-transparent p-2 transition-opacity duration-300 motion-reduce:transition-none">
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
        <header className={`reader-header relative w-full shrink-0 border-b border-border bg-background ${PAGE_LAYER_CLASSES.header}`}>
          <div className="flex h-full items-center justify-between">
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
        <Link to="/" aria-label="Panel Page Flip" className="flex min-h-10 min-w-10 items-center gap-2">
          <BookOpen className="h-6 w-6 text-comic-purple" />
          <span className="hidden font-comic text-2xl sm:inline">Panel Page Flip</span>
        </Link>
        <nav className="flex items-center gap-2 sm:gap-4">
          {isLoggedIn ? (
            <SignedInNav
              location={location}
              inBulkUpload={inBulkUpload}
              isAdmin={isAdmin}
              pendingInvitations={pendingInvitations}
              onLogout={onLogout}
            />
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
