import { beforeEach, expect, it, vi } from "vitest";

const root = vi.hoisted(() => ({ render: vi.fn() }));
const createRoot = vi.hoisted(() => vi.fn(() => root));

vi.mock("react-dom/client", () => ({ createRoot }));
vi.mock("./App.jsx", () => ({ default: () => <div>App</div> }));

beforeEach(() => {
  vi.resetModules();
  vi.clearAllMocks();
  document.body.innerHTML = '<div id="root"></div>';
});

it("mounts the application on the root element", async () => {
  await import("./main.jsx");

  expect(createRoot).toHaveBeenCalledWith(document.getElementById("root"));
  expect(root.render).toHaveBeenCalledOnce();
});
