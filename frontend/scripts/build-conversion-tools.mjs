/**
 * Packages the CBR-to-CBZ helper scripts as downloadable zips.
 *
 * The Settings page offers each script as a zip rather than a bare file so a
 * browser cannot try to render or execute it, and shows a SHA-256 so a
 * suspicious user can check what they downloaded. Both the zips and the
 * checksum module are committed, so this script also has a --check mode that
 * fails when they no longer match the sources.
 *
 *   node scripts/build-conversion-tools.mjs           # write the bundles
 *   node scripts/build-conversion-tools.mjs --check   # verify, write nothing
 *
 * The archives are written by hand rather than shelled out to zip/7z: it keeps
 * the build dependency-free, and fixing the entry timestamps makes the output
 * byte-identical every run, so rebuilding an unchanged script produces no diff.
 *
 * Entries are stored rather than deflated. Compressed output is only guaranteed
 * identical for a given zlib build, so deflating would let a different Node
 * version produce a different archive from the same sources and fail --check for
 * no real reason. These are a few kilobytes of text; the size is not worth it.
 */
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync, mkdirSync, existsSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const repoRoot = resolve(frontendDir, "..");
const sourceDir = join(repoRoot, "scripts", "comic-conversion");
const outputDir = join(frontendDir, "public", "tools");
const manifestPath = join(frontendDir, "src", "lib", "conversion-tools.js");

/** Bump when either script changes in a way users should notice. */
const VERSION = "1.0.0";

const BUNDLES = [
  {
    id: "windows",
    label: "Windows (PowerShell)",
    zipName: "convert-cbr-to-cbz-windows.zip",
    entries: [
      // CRLF: a Windows user may well open this in Notepad before running it.
      { name: "Convert-CbrToCbz.ps1", source: "Convert-CbrToCbz.ps1", eol: "crlf" },
      { name: "README.md", source: "README.md", eol: "crlf" },
    ],
  },
  {
    id: "linux",
    label: "Linux and macOS (bash)",
    zipName: "convert-cbr-to-cbz-linux.zip",
    entries: [
      // LF, and executable: a CRLF shebang makes the kernel look for an
      // interpreter named "/usr/bin/env bash\r".
      { name: "convert-cbr-to-cbz.sh", source: "convert-cbr-to-cbz.sh", eol: "lf", executable: true },
      { name: "README.md", source: "README.md", eol: "lf" },
    ],
  },
];

// A fixed DOS timestamp (1 Jan 1980, the earliest the format can express) so
// the archives do not change just because they were rebuilt.
const DOS_TIME = 0;
const DOS_DATE = 0x0021;

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[i] = c;
  }
  return table;
})();

function crc32(buffer) {
  let crc = -1;
  for (let i = 0; i < buffer.length; i++) {
    crc = (crc >>> 8) ^ CRC_TABLE[(crc ^ buffer[i]) & 0xff];
  }
  return (crc ^ -1) >>> 0;
}

/**
 * A ZIP archive containing the given entries, stored (compression method 0).
 * @param {{name: string, data: Buffer, executable?: boolean}[]} entries
 */
function buildZip(entries) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;

  for (const entry of entries) {
    const nameBytes = Buffer.from(entry.name, "utf8");
    const compressed = entry.data;
    const crc = crc32(entry.data);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4); // version needed
    local.writeUInt16LE(0, 6); // flags
    local.writeUInt16LE(0, 8); // stored
    local.writeUInt16LE(DOS_TIME, 10);
    local.writeUInt16LE(DOS_DATE, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(compressed.length, 18);
    local.writeUInt32LE(entry.data.length, 22);
    local.writeUInt16LE(nameBytes.length, 26);
    local.writeUInt16LE(0, 28); // extra field length
    localParts.push(local, nameBytes, compressed);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    // "Made by" Unix, so the mode below is honoured: the shell script needs to
    // arrive executable rather than making the user chmod it first.
    central.writeUInt16LE(0x031e, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0, 8);
    central.writeUInt16LE(0, 10); // stored
    central.writeUInt16LE(DOS_TIME, 12);
    central.writeUInt16LE(DOS_DATE, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(compressed.length, 20);
    central.writeUInt32LE(entry.data.length, 24);
    central.writeUInt16LE(nameBytes.length, 28);
    central.writeUInt16LE(0, 30); // extra
    central.writeUInt16LE(0, 32); // comment
    central.writeUInt16LE(0, 34); // disk number
    central.writeUInt16LE(0, 36); // internal attributes
    // >>> 0 because the shifted mode exceeds 2^31 and JS bitwise ops are signed.
    central.writeUInt32LE(((entry.executable ? 0o100755 : 0o100644) << 16) >>> 0, 38);
    central.writeUInt32LE(offset, 42);
    centralParts.push(central, nameBytes);

    offset += local.length + nameBytes.length + compressed.length;
  }

  const centralDirectory = Buffer.concat(centralParts);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(centralDirectory.length, 12);
  end.writeUInt32LE(offset, 16);
  end.writeUInt16LE(0, 20);

  return Buffer.concat([...localParts, centralDirectory, end]);
}

const sha256 = (buffer) => createHash("sha256").update(buffer).digest("hex");

function buildManifest(bundles) {
  const entries = bundles.map((bundle) => `  {
    id: ${JSON.stringify(bundle.id)},
    label: ${JSON.stringify(bundle.label)},
    href: ${JSON.stringify(`/tools/${bundle.zipName}`)},
    fileName: ${JSON.stringify(bundle.zipName)},
    sha256: ${JSON.stringify(bundle.sha256)},
    sizeBytes: ${bundle.sizeBytes},
  },`).join("\n");

  return `/**
 * Generated by scripts/build-conversion-tools.mjs — do not edit by hand.
 *
 * Run \`npm run build:tools\` after changing anything in
 * scripts/comic-conversion/, and commit the result along with the zips in
 * public/tools/.
 */

export const CONVERSION_TOOLS_VERSION = ${JSON.stringify(VERSION)};

export const CONVERSION_TOOLS = [
${entries}
];
`;
}

const check = process.argv.includes("--check");
const built = [];

for (const bundle of BUNDLES) {
  const entries = bundle.entries.map((entry) => {
    const path = join(sourceDir, entry.source);
    if (!existsSync(path)) {
      console.error(`Missing source file: ${path}`);
      process.exit(1);
    }

    // Normalise to LF first so the result does not depend on how git checked
    // the file out, then apply the endings this platform's copy wants. Same
    // bytes from a Windows or a Linux working tree, so the checksum is stable.
    const normalised = readFileSync(path, "utf8").replace(/\r\n/g, "\n");
    const text = entry.eol === "crlf" ? normalised.replace(/\n/g, "\r\n") : normalised;
    return { name: entry.name, data: Buffer.from(text, "utf8"), executable: entry.executable };
  });

  const zip = buildZip(entries);
  built.push({ ...bundle, zip, sha256: sha256(zip), sizeBytes: zip.length });
}

const manifest = buildManifest(built);
const stale = [];

for (const bundle of built) {
  const target = join(outputDir, bundle.zipName);
  const current = existsSync(target) ? readFileSync(target) : null;
  if (current && current.equals(bundle.zip)) continue;

  if (check) {
    stale.push(`public/tools/${bundle.zipName}`);
    continue;
  }
  mkdirSync(outputDir, { recursive: true });
  writeFileSync(target, bundle.zip);
  console.log(`wrote public/tools/${bundle.zipName} (${bundle.sizeBytes} bytes, sha256 ${bundle.sha256.slice(0, 16)}…)`);
}

const currentManifest = existsSync(manifestPath) ? readFileSync(manifestPath, "utf8") : null;
if (currentManifest?.replace(/\r\n/g, "\n") !== manifest) {
  if (check) {
    stale.push("src/lib/conversion-tools.js");
  } else {
    writeFileSync(manifestPath, manifest);
    console.log("wrote src/lib/conversion-tools.js");
  }
}

if (check) {
  if (stale.length > 0) {
    console.error("These files are out of date with scripts/comic-conversion/:");
    for (const file of stale) console.error(`  ${file}`);
    console.error("\nRun `npm run build:tools` and commit the result.");
    process.exit(1);
  }
  console.log("Conversion tool downloads are up to date.");
}
