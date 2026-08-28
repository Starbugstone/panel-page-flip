export { formatFileSize } from "@/lib/format";

export const CHUNK_SIZE_BYTES = 1024 * 1024;
// Every format the application knows about. What a given installation actually
// accepts is narrower and comes from the server, since an optional format needs
// a runtime the admin has to have installed and enabled; this list is only the
// fallback for stripping an extension off a title.
export const COMIC_EXTENSIONS = ["cbz", "cbr", "cb7", "cbt", "pdf"];
export const comicFileAccept = (extensions) => extensions.map((extension) => `.${extension}`).join(",");

const EXTENSION_SUFFIX = new RegExp(`\\.(${COMIC_EXTENSIONS.join("|")})$`, "i");

export function generateTitleFromFilename(filename) {
  const derived = filename
    .replace(EXTENSION_SUFFIX, "")
    .replace(/[_-]/g, " ")
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/([a-zA-Z])([0-9])/g, "$1 $2")
    .replace(/([0-9])([a-zA-Z])/g, "$1 $2")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

  // A name that is all extension and separators — ".cbz", "___.cbz" — derives
  // to nothing, and the queue will not upload a row without a title. The
  // filename is then the only title there is, so it is a better one than none.
  return derived || filename.trim();
}

export function isComicFile(file, extensions = COMIC_EXTENSIONS) {
  return extensions.includes(file?.name?.toLowerCase().split(".").pop());
}
