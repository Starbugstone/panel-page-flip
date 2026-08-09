export { formatFileSize } from "@/lib/format";

export const CHUNK_SIZE_BYTES = 1024 * 1024;
export const COMIC_EXTENSIONS = ["cbz", "cbr", "cb7", "cbt", "pdf"];
export const COMIC_FILE_ACCEPT = COMIC_EXTENSIONS.map((extension) => `.${extension}`).join(",");
export const comicFileAccept = (extensions) => extensions.map((extension) => `.${extension}`).join(",");

export function generateTitleFromFilename(filename) {
  return filename
    .replace(/\.(cbz|cbr|cb7|cbt|pdf)$/i, "")
    .replace(/[_-]/g, " ")
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/([a-zA-Z])([0-9])/g, "$1 $2")
    .replace(/([0-9])([a-zA-Z])/g, "$1 $2")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function isCbzFile(file, extensions = COMIC_EXTENSIONS) {
  return extensions.includes(file?.name?.toLowerCase().split(".").pop());
}

export const isComicFile = isCbzFile;
