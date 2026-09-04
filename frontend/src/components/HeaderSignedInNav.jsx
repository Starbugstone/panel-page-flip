import { useEffect, useRef } from "react";
import { Link } from "react-router-dom";
import { BookOpen, Cloud, Files, LogOut, Menu, Settings, Share2, SlidersHorizontal, Upload } from "lucide-react";
import { Button } from "./ui/button.jsx";
import { BulkUploadEntryLink } from "./BulkUploadEntryLink.jsx";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from "./ui/dropdown-menu.jsx";
import { isDestinationActive, NAVIGATION_DESTINATIONS } from "@/lib/navigation";
import { cn } from "@/lib/utils";

const icons = { library: BookOpen, upload: Upload, bulk: Files, sharing: Share2, dropbox: Cloud, settings: SlidersHorizontal, admin: Settings };

function pendingInvitationLabel(prefix, count) {
  if (count <= 0) return prefix;
  return `${prefix}, ${count} pending ${count === 1 ? "invitation" : "invitations"}`;
}

function NavigationLink({ destination, location, pendingInvitations, compact = false, className, ...props }) {
  const active = isDestinationActive(destination, location.pathname);
  const pending = destination.icon === "sharing" ? pendingInvitations : 0;
  const Icon = icons[destination.icon];
  const content = <>{compact && <Icon aria-hidden="true" className="h-5 w-5" />}{destination.label}{pending > 0 && ` (${pending})`}</>;
  const linkProps = {
    ...props,
    "aria-current": active ? "page" : undefined,
    "aria-label": pendingInvitationLabel(destination.label, pending),
    className: cn(
      "flex items-center gap-2 rounded-md text-sm font-medium transition-colors",
      compact ? "min-h-11 w-full px-3" : "min-h-10 px-2",
      active ? "bg-primary/10 text-primary" : "text-muted-foreground hover:bg-accent hover:text-foreground",
      className,
    ),
  };

  if (destination.icon === "bulk" && !active) {
    return <BulkUploadEntryLink {...linkProps}>{content}</BulkUploadEntryLink>;
  }

  const to = destination.icon === "bulk"
    ? `${location.pathname}${location.search}${location.hash}`
    : destination.path;
  return <Link to={to} {...linkProps}>{content}</Link>;
}

export function SignedInNav({ location, isAdmin, pendingInvitations, onLogout }) {
  const openedAt = useRef(location.pathname);
  const currentPath = useRef(location.pathname);
  useEffect(() => { currentPath.current = location.pathname; }, [location.pathname]);
  const destinations = NAVIGATION_DESTINATIONS.filter((destination) => !destination.adminOnly || isAdmin);
  const shared = { location, pendingInvitations };

  return (
    <>
      <div className="hidden items-center gap-1 xl:flex">
        {destinations.map((destination) => <NavigationLink key={destination.path} destination={destination} {...shared} />)}
        <Button variant="ghost" onClick={onLogout}><LogOut aria-hidden="true" />Logout</Button>
      </div>
      <DropdownMenu onOpenChange={(open) => { if (open) openedAt.current = location.pathname; }}>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="relative xl:hidden" aria-label={pendingInvitationLabel("Open navigation menu", pendingInvitations)}>
            <Menu aria-hidden="true" className="h-5 w-5" />
            {pendingInvitations > 0 && <span aria-hidden="true" className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-primary" />}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-64 max-w-[calc(100vw-2rem)] xl:hidden"
          onCloseAutoFocus={(event) => {
            if (openedAt.current === currentPath.current) return;
            event.preventDefault();
            document.getElementById("main-content")?.focus({ preventScroll: true });
          }}
        >
          {destinations.map((destination) => (
            <DropdownMenuItem asChild key={destination.path}>
              <NavigationLink destination={destination} compact {...shared} />
            </DropdownMenuItem>
          ))}
          <DropdownMenuSeparator />
          <DropdownMenuItem className="flex min-h-11 items-center gap-3" onSelect={onLogout}>
            <LogOut aria-hidden="true" className="h-5 w-5" />Logout
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </>
  );
}
