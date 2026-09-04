import { useEffect, useState } from "react";
import { BookOpen } from "lucide-react";
import { Link, useLocation } from "react-router-dom";

import { BackToLibraryLink } from "./BackToLibraryLink.jsx";
import { ThemeToggle } from "./ThemeToggle.jsx";
import { SignedInNav } from "./HeaderSignedInNav.jsx";
import { PAGE_LAYER_CLASSES } from "@/lib/overlay-layers.js";
import { useSharing } from "@/hooks/use-sharing.jsx";

export function Header({ isLoggedIn, onLogout, isAdmin }) {
  const location = useLocation();
  const isReaderPage = location.pathname.startsWith("/read/");
  const isLibraryPage = location.pathname === "/dashboard";
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
            <BackToLibraryLink className="text-foreground hover:text-primary text-sm" />
            <ThemeToggle />
          </div>
        </header>
      );
    } else {
      // Compact header for normal mode with relative positioning
      return (
        <header className={`reader-header relative w-full shrink-0 border-b border-border bg-background ${PAGE_LAYER_CLASSES.header}`}>
          <div className="flex h-full items-center justify-between">
            <BackToLibraryLink className="text-foreground hover:text-primary text-sm" />
            <ThemeToggle />
          </div>
        </header>
      );
    }
  }

  return (
    <header className={`border-b ${isLibraryPage ? `bg-background lg:sticky lg:top-0 ${PAGE_LAYER_CLASSES.header}` : ""}`}>
      <div className="container flex h-16 items-center justify-between px-4 md:px-6">
        <Link to="/" aria-label="Panel Page Flip" className="flex min-h-10 min-w-10 items-center gap-2">
          <BookOpen aria-hidden="true" className="h-6 w-6 text-primary" />
          <span className="hidden text-xl font-semibold tracking-tight sm:inline">Panel Page Flip</span>
        </Link>
        <nav aria-label="Main navigation" className="flex items-center gap-2 sm:gap-3">
          {isLoggedIn ? (
            <SignedInNav
              location={location}
              isAdmin={isAdmin}
              pendingInvitations={pendingInvitations}
              onLogout={onLogout}
            />
          ) : (
            <Link 
              to="/login" 
              aria-current={location.pathname === "/login" ? "page" : undefined}
              className="rounded-md px-3 py-2 text-sm font-medium text-primary hover:bg-primary/10"
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
