
import { Link } from "react-router-dom";
import { ThemeToggle } from "./ThemeToggle.jsx";
import { useLocation } from "react-router-dom";
import { Button } from "./ui/button.jsx";
import { BookOpen, Upload, Files, Settings, User, Cloud, SlidersHorizontal, Share2 } from "lucide-react";
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
        <Link to="/" className="flex items-center gap-2">
          <BookOpen className="h-6 w-6 text-comic-purple" />
          <span className="hidden font-comic text-2xl sm:inline">Panel Page Flip</span>
        </Link>
        <nav className="flex items-center gap-2 sm:gap-4">
          {isLoggedIn ? (
            <>
              {/* Every one of these collapses to an icon at some breakpoint, so
                  each carries an aria-label — the visible text disappears but
                  the accessible name has to survive. */}
              <Link to="/dashboard" aria-label="My Comics" className={`${location.pathname === "/dashboard" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden sm:inline">My Comics</span>
                <BookOpen className="h-5 w-5 sm:hidden" />
              </Link>
              <Link to="/upload" aria-label="Upload Comic" className={`${location.pathname === "/upload" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden md:inline">Upload Comic</span>
                <Upload className="inline md:hidden h-5 w-5" />
              </Link>
              {/* Bulk upload is two routes now — the gate at /upload/bulk and the
                  batch at /upload/bulk/session — so the highlight has to cover
                  both or it goes dark for the whole time the queue is on screen.
                  The link points at wherever the user already is, because
                  sending somebody mid-batch back through the gate unmounts the
                  queue and loses every title, tag and progress bar in it. */}
              <Link to={inBulkUpload ? location.pathname : "/upload/bulk"} aria-label="Bulk Upload" className={`${inBulkUpload ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden lg:inline">Bulk Upload</span>
                <Files className="inline lg:hidden h-5 w-5" />
              </Link>
              {/* The count is part of the accessible name, not decoration: the
                  label collapses to an icon at narrow widths and "Sharing"
                  alone would not say that anything is waiting. */}
              <Link
                to="/sharing"
                aria-label={pendingInvitations > 0
                  ? `Sharing, ${pendingInvitations} pending ${pendingInvitations === 1 ? "invitation" : "invitations"}`
                  : "Sharing"}
                className={`relative ${location.pathname === "/sharing" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
              >
                <span className="hidden md:inline">
                  Sharing{pendingInvitations > 0 ? ` (${pendingInvitations})` : ""}
                </span>
                <Share2 className="inline md:hidden h-5 w-5" />
                {pendingInvitations > 0 && (
                  <span
                    aria-hidden="true"
                    className="absolute -right-2 -top-1 h-2 w-2 rounded-full bg-comic-purple md:hidden"
                  />
                )}
              </Link>
              <Link to="/dropbox-sync" aria-label="Dropbox Import" className={`${location.pathname === "/dropbox-sync" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden md:inline">Dropbox Import</span>
                <Cloud className="inline md:hidden h-5 w-5" />
              </Link>
              <Link to="/settings" aria-label="Settings" className={`${location.pathname === "/settings" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                <span className="hidden lg:inline">Settings</span>
                <SlidersHorizontal className="inline lg:hidden h-5 w-5" />
              </Link>
              {isAdmin && (
                <Link to="/admin" aria-label="Admin dashboard" className={`${location.pathname === "/admin" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}>
                  <span className="hidden md:inline">Admin dashboard</span>
                  <Settings className="inline md:hidden h-5 w-5" />
                </Link>
              )}
              <div className="flex items-center gap-4">
                <Button variant="ghost" onClick={onLogout} className="hidden md:flex gap-2">
                  <User size={16} />
                  Logout
                </Button>
                <Button variant="ghost" size="icon" onClick={onLogout} className="md:hidden" aria-label="Logout">
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
