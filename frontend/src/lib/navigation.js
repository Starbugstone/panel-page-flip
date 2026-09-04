export const NAVIGATION_DESTINATIONS = [
  { path: "/dashboard", label: "My Comics", icon: "library" },
  { path: "/upload", label: "Upload Comic", icon: "upload" },
  { path: "/upload/bulk", label: "Bulk Upload", icon: "bulk", descendants: true },
  { path: "/sharing", label: "Sharing", icon: "sharing" },
  { path: "/dropbox-sync", label: "Dropbox Import", icon: "dropbox" },
  { path: "/settings", label: "Settings", icon: "settings" },
  { path: "/admin", label: "Admin dashboard", icon: "admin", descendants: true, adminOnly: true },
];

export function isDestinationActive(destination, pathname) {
  return pathname === destination.path
    || (destination.descendants === true && pathname.startsWith(`${destination.path}/`));
}

const publicTitles = {
  "/": "Your comic collection",
  "/login": "Login",
  "/complete-social-signup": "Complete social signup",
  "/forgot-password": "Reset password",
  "/email-verification": "Email verification",
  "/privacy": "Privacy Policy",
  "/terms": "Terms of Service",
  "/cookies": "Cookie Notice",
  "/report-content": "Report illegal content",
};

export function pageTitle(pathname) {
  if (pathname.startsWith("/read/")) return "Comic reader";
  if (pathname.startsWith("/reset-password/")) return "Reset password";
  if (pathname.startsWith("/share/invitation/")) return "Comic invitation";
  return publicTitles[pathname]
    ?? NAVIGATION_DESTINATIONS.find((destination) => isDestinationActive(destination, pathname))?.label
    ?? "Page not found";
}
