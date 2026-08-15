import { Link, useLocation } from "react-router-dom";

export function Footer() {
  const { pathname } = useLocation();
  if (pathname.startsWith("/read/")) return null;

  return (
    <footer className="border-t py-6">
      <nav aria-label="Legal" className="container mx-auto flex flex-wrap gap-x-5 gap-y-2 px-4 text-sm text-muted-foreground md:px-6">
        <Link className="hover:text-foreground" to="/privacy">Privacy</Link>
        <Link className="hover:text-foreground" to="/terms">Terms</Link>
        <Link className="hover:text-foreground" to="/cookies">Cookies</Link>
        <Link className="hover:text-foreground" to="/report-content">Report illegal content</Link>
      </nav>
    </footer>
  );
}
