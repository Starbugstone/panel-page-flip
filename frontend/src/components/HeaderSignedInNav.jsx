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
      <div className="hidden items-center gap-4 xl:flex">
        <Link
          to="/dashboard"
          aria-label="My Comics"
          className={`flex min-h-10 items-center ${location.pathname === "/dashboard" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
        >
          My Comics
        </Link>
        <Link
          to="/upload"
          aria-label="Upload Comic"
          className={`flex min-h-10 items-center ${location.pathname === "/upload" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
        >
          Upload Comic
        </Link>
        {/* Bulk upload is two routes, so the highlight has to cover both.
            Mid-batch the link stays where the user already is and remains a
            router navigation, avoiding a reload that would lose work. */}
        {inBulkUpload ? (
          <Link
            to={`${location.pathname}${location.search}`}
            aria-label="Bulk Upload"
            className="flex min-h-10 items-center text-comic-purple"
          >
            Bulk Upload
          </Link>
        ) : (
          <BulkUploadEntryLink
            aria-label="Bulk Upload"
            className="flex min-h-10 items-center text-foreground hover:text-comic-purple"
          >
            Bulk Upload
          </BulkUploadEntryLink>
        )}
        <Link
          to="/sharing"
          aria-label={pendingInvitations > 0
            ? `Sharing, ${pendingInvitations} pending ${pendingInvitations === 1 ? "invitation" : "invitations"}`
            : "Sharing"}
          className={`relative flex min-h-10 items-center ${location.pathname === "/sharing" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
        >
          Sharing{pendingInvitations > 0 ? ` (${pendingInvitations})` : ""}
        </Link>
        <Link
          to="/dropbox-sync"
          aria-label="Dropbox Import"
          className={`flex min-h-10 items-center ${location.pathname === "/dropbox-sync" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
        >
          Dropbox Import
        </Link>
        <Link
          to="/settings"
          aria-label="Settings"
          className={`flex min-h-10 items-center ${location.pathname === "/settings" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
        >
          Settings
        </Link>
        {isAdmin && (
          <Link
            to="/admin"
            aria-label="Admin dashboard"
            className={`flex min-h-10 items-center ${location.pathname === "/admin" ? "text-comic-purple" : "text-foreground hover:text-comic-purple"}`}
          >
            Admin dashboard
          </Link>
        )}
        <Button variant="ghost" onClick={onLogout} className="gap-2">
          <User size={16} />
          Logout
        </Button>
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="relative xl:hidden"
            aria-label={pendingInvitations > 0
              ? `Open navigation menu, ${pendingInvitations} pending ${pendingInvitations === 1 ? "invitation" : "invitations"}`
              : "Open navigation menu"}
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
    </>
  );
}
