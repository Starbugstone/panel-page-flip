import { describe, expect, it } from "vitest";

import { accountDeletionReauthenticationUrl } from "./account-deletion";

describe("account deletion", () => {
  it("starts provider reauthentication and returns to settings", () => {
    expect(accountDeletionReauthenticationUrl("google")).toBe(
      "/api/auth/oauth/google/start?purpose=delete-account&redirect=%2Fsettings"
    );
  });
});
