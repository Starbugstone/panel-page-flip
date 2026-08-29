import { describe, expect, it } from "vitest";
import {
  buildUserUpdatePayload,
  describeRoles,
  ROLE_ADMIN,
  ROLE_EDITOR,
  ROLE_USER,
  toggleNewUserRole,
  toggleRole,
} from "./admin-user-roles";

describe("toggleRole", () => {
  it("adds a role that was not there", () => {
    expect(toggleRole([ROLE_USER], ROLE_ADMIN, true)).toEqual([ROLE_USER, ROLE_ADMIN]);
  });

  it("removes a role that was", () => {
    expect(toggleRole([ROLE_USER, ROLE_ADMIN], ROLE_ADMIN, false)).toEqual([ROLE_USER]);
  });

  it("does not add a role twice", () => {
    expect(toggleRole([ROLE_USER, ROLE_ADMIN], ROLE_ADMIN, true)).toEqual([ROLE_USER, ROLE_ADMIN]);
  });
});

describe("toggleNewUserRole", () => {
  // The base role every account has. The form shows it ticked and disabled, so
  // the tick boxes have to describe what the account will actually get.
  it("keeps the base role when the only other one is removed", () => {
    expect(toggleNewUserRole([ROLE_USER, ROLE_ADMIN], ROLE_ADMIN, false)).toEqual([ROLE_USER]);
  });

  it("keeps the base role alongside an added one", () => {
    expect(toggleNewUserRole([ROLE_USER], ROLE_ADMIN, true)).toEqual([ROLE_USER, ROLE_ADMIN]);
  });

  it("puts the base role back if it was somehow missing", () => {
    expect(toggleNewUserRole([], ROLE_ADMIN, true)).toEqual([ROLE_ADMIN, ROLE_USER]);
  });
});

describe("buildUserUpdatePayload", () => {
  const admin = { id: 1 };
  const user = { id: 2, name: "Ada", roles: [ROLE_USER] };
  const form = { name: "Ada", email: "ada@test.local", password: "", roles: [ROLE_USER] };

  it("sends nothing when nothing changed", () => {
    expect(buildUserUpdatePayload(form, user, admin)).toEqual({});
  });

  it("sends a changed name, trimmed", () => {
    expect(buildUserUpdatePayload({ ...form, name: "  Grace  " }, user, admin)).toEqual({ name: "Grace" });
  });

  // Otherwise clearing the box would rename the account to nothing.
  it("ignores a name that was emptied rather than changed", () => {
    expect(buildUserUpdatePayload({ ...form, name: "   " }, user, admin)).toEqual({});
  });

  // A blank password means "keep the current one", so it must never be sent.
  it("sends a password only when one was typed", () => {
    expect(buildUserUpdatePayload({ ...form, password: "  hunter2  " }, user, admin)).toEqual({ password: "hunter2" });
    expect(buildUserUpdatePayload({ ...form, password: "   " }, user, admin)).toEqual({});
  });

  it("sends changed roles with the base role kept", () => {
    const payload = buildUserUpdatePayload({ ...form, roles: [ROLE_ADMIN] }, user, admin);
    expect(payload.roles.sort()).toEqual([ROLE_ADMIN, ROLE_USER]);
  });

  it("leaves roles out when they match what the account already has", () => {
    const held = { ...user, roles: [ROLE_ADMIN, ROLE_USER] };
    expect(buildUserUpdatePayload({ ...form, roles: [ROLE_USER, ROLE_ADMIN] }, held, admin)).toEqual({});
  });

  /**
   * The tick box for one's own roles is disabled; this is the other half of
   * that safeguard. An administrator must not be able to remove their own
   * administrator role and lock the installation out of its own admin screens.
   */
  it("refuses to change the signed-in administrator's own roles", () => {
    const self = { id: 1, name: "Ada", roles: [ROLE_ADMIN, ROLE_USER] };
    const payload = buildUserUpdatePayload({ ...form, name: "Grace", roles: [ROLE_USER] }, self, admin);

    expect(payload).toEqual({ name: "Grace" });
    expect(payload).not.toHaveProperty("roles");
  });
});

describe("describeRoles", () => {
  it("names each granted role", () => {
    expect(describeRoles([ROLE_USER, ROLE_ADMIN]).map((badge) => badge.label)).toEqual(["Admin"]);
    expect(describeRoles([ROLE_USER, ROLE_EDITOR]).map((badge) => badge.label)).toEqual(["Editor"]);
    expect(describeRoles([ROLE_USER, ROLE_ADMIN, ROLE_EDITOR]).map((badge) => badge.label)).toEqual(["Admin", "Editor"]);
  });

  // An ordinary account still needs something in the column.
  it("falls back to the base role when it is the only one", () => {
    expect(describeRoles([ROLE_USER]).map((badge) => badge.label)).toEqual(["User"]);
  });
});
