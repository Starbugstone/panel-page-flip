import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { resolveBulkUploadAccess } from "@/lib/bulk-upload-session";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));

const serverSays = (session) => vi.mocked(api.get).mockResolvedValue(session);

describe("what happens when somebody asks for bulk upload", () => {
  beforeEach(() => vi.clearAllMocks());

  it("offers the rewarded advertisement when everything needed for one is present", async () => {
    serverSays({ active: false, gateRequired: true });

    await expect(resolveBulkUploadAccess({ scriptStatus: "ready" })).resolves.toBe("offer");
  });

  it("opens the uploader where the installation shows no advertising", async () => {
    serverSays({ active: false, gateRequired: false });

    await expect(resolveBulkUploadAccess({ scriptStatus: "idle" })).resolves.toBe("open");
  });

  /** An ad blocker, a failed script, or no rewarded inventory. */
  it("opens the uploader when no rewarded advertisement can be served", async () => {
    serverSays({ active: false, gateRequired: true });

    await expect(resolveBulkUploadAccess({ scriptStatus: "unavailable" })).resolves.toBe("open");
  });

  /**
   * One advertisement covers the batch. Coming back to the gate part way
   * through must not ask for another.
   */
  it("opens the uploader while a batch is already in progress", async () => {
    serverSays({ active: true, gateRequired: true });

    await expect(resolveBulkUploadAccess({ scriptStatus: "ready" })).resolves.toBe("open");
  });

  it("opens the uploader when the server could not be asked", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("Unable to reach the server"));

    await expect(resolveBulkUploadAccess({ scriptStatus: "ready" })).resolves.toBe("open");
  });

  it("opens the uploader when the server answered with nothing useful", async () => {
    serverSays(null);

    await expect(resolveBulkUploadAccess({ scriptStatus: "ready" })).resolves.toBe("open");
  });
});
