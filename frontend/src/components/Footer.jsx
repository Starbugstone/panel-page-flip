import { Link, useLocation } from "react-router-dom";

import { PrivacyChoicesButton } from "@/components/ads/PrivacyChoicesButton.jsx";

export function Footer() {
  const { pathname } = useLocation();
  if (pathname.startsWith("/read/")) return null;

  return (
    <footer className="border-t py-6 text-sm text-muted-foreground">
      <div className="container mx-auto flex flex-col gap-3 px-4 md:flex-row md:items-center md:justify-between md:px-6">
        <p>© {new Date().getFullYear()} Comic Reader App. All rights reserved.</p>
        <nav aria-label="Legal" className="flex flex-wrap gap-x-5 gap-y-2">
          <Link className="hover:text-foreground" to="/privacy">Privacy</Link>
          <Link className="hover:text-foreground" to="/terms">Terms</Link>
          <Link className="hover:text-foreground" to="/cookies">Cookies</Link>
          <Link className="hover:text-foreground" to="/report-content">Report illegal content</Link>
          <a
            className="hover:text-foreground"
            href="https://github.com/Starbugstone/panel-page-flip/issues"
            target="_blank"
            rel="noreferrer"
          >
            Report a bug
          </a>
          <PrivacyChoicesButton className="hover:text-foreground underline-offset-4 hover:underline" />
        </nav>
      </div>
    </footer>
  );
}
