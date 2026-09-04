import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const stylesheet = readFileSync(new URL("../index.css", import.meta.url), "utf8");

function luminance(hsl) {
  const [hue, saturation, lightness] = hsl.split(/\s+/).map(parseFloat);
  const s = saturation / 100;
  const l = lightness / 100;
  const a = s * Math.min(l, 1 - l);
  const channel = (n) => {
    const k = (n + hue / 30) % 12;
    const value = l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1));
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(0) + 0.7152 * channel(8) + 0.0722 * channel(4);
}

const pairs = [
  ["foreground", "background"], ["card-foreground", "card"],
  ["muted-foreground", "background"], ["muted-foreground", "card"],
  ["primary", "background"], ["primary-foreground", "primary"],
  ["destructive", "background"], ["destructive-foreground", "destructive"],
  ["secondary-foreground", "secondary"], ["accent-foreground", "accent"],
];

describe.each([":root", ".dark"])("%s semantic text contrast", (selector) => {
  const body = stylesheet.slice(stylesheet.indexOf(`${selector} {`)).split("}")[0];
  const tokens = Object.fromEntries([...body.matchAll(/--([\w-]+):\s*([^;]+);/g)].map(([, name, value]) => [name, value]));

  it.each(pairs)("keeps %s on %s at least 4.5:1", (text, surface) => {
    const values = [luminance(tokens[text]), luminance(tokens[surface])].sort((a, b) => b - a);
    expect((values[0] + 0.05) / (values[1] + 0.05)).toBeGreaterThanOrEqual(4.5);
  });
});
