export const ROLE_USER = "ROLE_USER";
export const ROLE_ADMIN = "ROLE_ADMIN";
export const ROLE_EDITOR = "ROLE_EDITOR";

/** Add or drop a role, keeping the list free of duplicates. */
export function toggleRole(roles, role, enabled) {
  const next = enabled ? [...roles, role] : roles.filter((entry) => entry !== role);
  return Array.from(new Set(next));
}

/**
 * The same, for an account being created.
 *
 * `ROLE_USER` is the base every account has; the server grants it regardless,
 * and the box for it in the form is disabled. Keeping it here means the tick
 * box for Administrator shows the roles the new account will actually get.
 */
export function toggleNewUserRole(roles, role, enabled) {
  const next = toggleRole(roles, role, enabled);
  return next.includes(ROLE_USER) ? next : [...next, ROLE_USER];
}

const sameRoles = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());

/**
 * Only what actually changed.
 *
 * An untouched field is left out rather than sent back unchanged, so a
 * concurrent edit is not silently reverted by an administrator who opened the
 * dialog and pressed Save. A blank password means "keep the current one", which
 * is why it can never be sent as an empty string.
 *
 * An administrator cannot change their own roles here. The safeguard is the
 * disabled tick box, and this is the second half of it: a payload built from a
 * form that somehow got edited anyway still leaves roles alone.
 */
export function buildUserUpdatePayload(form, user, currentUser) {
  const payload = {};

  const name = form.name?.trim() ?? "";
  if (name !== "" && name !== user.name) payload.name = name;

  const password = form.password?.trim() ?? "";
  if (password !== "") payload.password = password;

  const editingSelf = Boolean(currentUser) && user.id === currentUser.id;
  if (!editingSelf && !sameRoles(form.roles, user.roles || [])) {
    payload.roles = Array.from(new Set([...form.roles, ROLE_USER]));
  }

  return payload;
}

/** The badges a row shows: the granted roles, or "User" when that is all there is. */
export function describeRoles(roles) {
  const badges = [];
  if (roles.includes(ROLE_ADMIN)) badges.push({ role: ROLE_ADMIN, label: "Admin", variant: "default" });
  if (roles.includes(ROLE_EDITOR)) badges.push({ role: ROLE_EDITOR, label: "Editor", variant: "secondary" });
  if (roles.length === 1 && roles.includes(ROLE_USER)) badges.push({ role: ROLE_USER, label: "User", variant: "outline" });
  return badges;
}
