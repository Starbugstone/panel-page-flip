import { describe, expect, it } from "vitest";
import { validatePassword } from "./password-policy";

describe("validatePassword", () => {
  it("accepts a password that meets every rule", () => {
    expect(validatePassword("Str0ng!Password")).toEqual([]);
  });

  it("lists every rule a weak password misses", () => {
    expect(validatePassword("qwerty")).toEqual([
      "At least 12 characters",
      "Uppercase and lowercase letters",
      "At least one digit",
      "At least one symbol",
    ]);
  });
});
