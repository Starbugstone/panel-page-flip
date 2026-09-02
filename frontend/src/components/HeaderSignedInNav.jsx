import { Link } from "react-router-dom";
import { BookOpen, Cloud, Files, Menu, Settings, Share2, SlidersHorizontal, Upload, User } from "lucide-react";

import { Button } from "./ui/button.jsx";
import { BulkUploadEntryLink } from "./BulkUploadEntryLink.jsx";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "./ui/dropdown-menu.jsx";

const itemClass = "flex min-h-11 w-full items-center gap-3";

function MobileLink({ to, icon: Icon, children }) {
  return (
    <DropdownMenuItem asChild>
      <Link to={to} className={itemClass}>
        <Icon className="h-5 w-5" />
        {children}
      </Link>
    </DropdownMenuItem>
  );
}

function desktopLinkClass(active) {
  return `flex min-h-10 items-center ${active ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`;
}

function pendingInvitationLabel(prefix, count) {
  if (count <= 0) return prefix;
  const noun = count === 1 ? "invitation" : "invitations";
  return `${prefix}, ${count} pending ${noun}`;
}

function DesktopNav({ location, inBulkUpload, isAdmin, pendingInvitations, onLogout }) {
  return (
    <div className="hidden items-center gap-4 xl:flex">
      <Link to="/dashboard" aria-label="My Comics" className={desktopLinkClass(location.pathname === "/dashboard")}>My Comics</Link>
      <Link to="/upload" aria-label="Upload Comic" className={desktopLinkClass(location.pathname === "/upload")}>Upload Comic</Link>
      {/* Bulk upload is two routes, so the highlight has to cover both.
          Mid-batch the link stays where the user already is and remains a
          router navigation, avoiding a reload that would lose work. */}
      {inBulkUpload ? (
        <Link to={`${location.pathname}${location.search}`} aria-label="Bulk Upload" className="flex min-h-10 items-center text-comic-purple">
          Bulk Upload
        </Link>
      ) : (
        <BulkUploadEntryLink aria-label="Bulk Upload" className="flex min-h-10 items-center text-foreground hover:text-comic-purple">
          Bulk Upload
        </BulkUploadEntryLink>
      )}
      <Link
        to="/sharing"
        aria-label={pendingInvitationLabel("Sharing", pendingInvitations)}
        className={`relative ${desktopLinkClass(location.pathname === "/sharing")}`}
      >
        Sharing{pendingInvitations > 0 ? ` (${pendingInvitations})` : ""}
      </Link>
      <Link to="/dropbox-sync" aria-label="Dropbox Import" className={desktopLinkClass(location.pathname === "/dropbox-sync")}>Dropbox Import</Link>
      <Link to="/settings" aria-label="Settings" className={desktopLinkClass(location.pathname === "/settings")}>Settings</Link>
      {isAdmin && (
        <Link to="/admin" aria-label="Admin dashboard" className={desktopLinkClass(location.pathname === "/admin")}>
          Admin dashboard
        </Link>
      )}
      <Button variant="ghost" onClick={onLogout} className="gap-2">
        <User size={16} />
        Logout
      </Button>
    </div>
  );
}

function MobileNav({ location, inBulkUpload, isAdmin, pendingInvitations, onLogout }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative xl:hidden"
          aria-label={pendingInvitationLabel("Open navigation menu", pendingInvitations)}
        >
          <Menu className="h-5 w-5" />
          {pendingInvitations > 0 && (
            <span aria-hidden="true" className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-comic-purple" />
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64 xl:hidden">
        <MobileLink to="/dashboard" icon={BookOpen}>My Comics</MobileLink>
        <MobileLink to="/upload" icon={Upload}>Upload Comic</MobileLink>
        <DropdownMenuItem asChild>
          {inBulkUpload ? (
            <Link to={`${location.pathname}${location.search}`} className={itemClass}>
              <Files className="h-5 w-5" /> Bulk Upload
            </Link>
          ) : (
            <BulkUploadEntryLink className={itemClass}>
              <Files className="h-5 w-5" /> Bulk Upload
            </BulkUploadEntryLink>
          )}
        </DropdownMenuItem>
        <MobileLink to="/sharing" icon={Share2}>
          Sharing{pendingInvitations > 0 ? ` (${pendingInvitations})` : ""}
        </MobileLink>
        <MobileLink to="/dropbox-sync" icon={Cloud}>Dropbox Import</MobileLink>
        <MobileLink to="/settings" icon={SlidersHorizontal}>Settings</MobileLink>
        {isAdmin && <MobileLink to="/admin" icon={Settings}>Admin dashboard</MobileLink>}
        <DropdownMenuSeparator />
        <DropdownMenuItem className={itemClass} onSelect={onLogout}>
          <User className="h-5 w-5" /> Logout
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/**
 * The signed-in navigation.
 *
 * Compact screens use one touch-friendly menu; wide screens expose every
 * destination directly. Desktop labels that collapse to icons retain an
 * explicit accessible name.
 */
export function SignedInNav({ location, inBulkUpload, isAdmin, pendingInvitations, onLogout }) {
  return (
    <>
      <DesktopNav {...{ location, inBulkUpload, isAdmin, pendingInvitations, onLogout }} />
      <MobileNav {...{ location, inBulkUpload, isAdmin, pendingInvitations, onLogout }} />
    </>
  );
}
