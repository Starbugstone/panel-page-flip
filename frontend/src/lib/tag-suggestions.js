/**
 * Tag name matching, shared by every control that offers existing tags.
 *
 * The rules here decide whether typing "Sci Fi" means the tag you already have
 * or a new one, so they live in one place: the bulk toolbar, the comic edit
 * dialog and the upload form must all answer that question the same way, or
 * users end up with "sci fi", "Sci Fi" and "Sci  Fi" side by side.
 *
 * The backend applies the same case-insensitive lookup when a tag is attached
 * (see TagRepository::findAvailableByName), so a near-miss here is a cosmetic
 * problem rather than a duplicate row — but the user still deserves to see the
 * existing tag before they invent a second one.
 */

/** A tag as the API returns it, or just its name. */
const nameOf = (tag) => (typeof tag === "string" ? tag : tag?.name ?? "");

/**
 * Trim and collapse internal whitespace. This is the canonical form that gets
 * submitted, so "  Sci   Fi " and "Sci Fi" are the same tag.
 * @param {unknown} value
 * @returns {string}
 */
export function normalizeTagName(value) {
  return String(value ?? "").trim().replace(/\s+/g, " ");
}

/**
 * The key two tag names are compared on: normalized and case-folded.
 * @param {unknown} value A tag object or a raw name.
 * @returns {string}
 */
export function tagKeyOf(value) {
  return normalizeTagName(nameOf(value)).toLowerCase();
}

/**
 * The tag whose name matches `value`, ignoring case and surrounding whitespace.
 *
 * A global tag wins over a personal one with the same name: that is the order
 * the backend resolves them in, so the UI must not promise otherwise.
 *
 * @param {Array<object|string>} tags
 * @param {unknown} value
 * @returns {object|string|undefined}
 */
export function findTagByName(tags, value) {
  const key = tagKeyOf(value);
  if (!key) return undefined;

  const matches = (tags || []).filter((tag) => tagKeyOf(tag) === key);

  return matches.find((tag) => typeof tag === "object" && tag?.isGlobal) ?? matches[0];
}

/**
 * The tags to offer for a query, most relevant first.
 *
 * Substring matching rather than fuzzy matching: a tag list is short and
 * hand-written, and a fuzzy hit that is not what you typed is worse than no hit
 * when the alternative on offer is "create it".
 *
 * @param {Array<object|string>} tags
 * @param {string} query Empty shows everything, which is what focusing the field does.
 * @param {{limit?: number}} [options]
 * @returns {Array<object|string>}
 */
export function filterTags(tags, query, { limit = 50 } = {}) {
  const key = tagKeyOf(query);

  const scored = (tags || [])
    .map((tag) => ({ tag, key: tagKeyOf(tag) }))
    .filter((entry) => entry.key !== "" && (key === "" || entry.key.includes(key)));

  scored.sort((a, b) => {
    if (key !== "") {
      const rank = Number(b.key.startsWith(key)) - Number(a.key.startsWith(key));
      if (rank !== 0) return rank;
    }

    const globalRank = Number(isGlobalTag(b.tag)) - Number(isGlobalTag(a.tag));
    if (globalRank !== 0) return globalRank;

    return a.key.localeCompare(b.key);
  });

  return scored.slice(0, limit).map((entry) => entry.tag);
}

/** Global tags belong to the install and are shown to everyone. */
export function isGlobalTag(tag) {
  return typeof tag === "object" && tag?.isGlobal === true;
}

/**
 * What submitting the current input would do.
 *
 * - `empty`     — nothing typed or selected; the control should stay disabled.
 * - `duplicate` — already applied to everything being edited; nothing to do.
 * - `existing`  — resolves to a tag that already exists; submit its name.
 * - `new`       — no existing tag matches; submitting creates one.
 *
 * @param {Array<object|string>} tags Tags available to the current user.
 * @param {string} value The raw input value.
 * @param {Array<object|string>} [applied] Tags already on the target(s).
 * @returns {{status: "empty"|"duplicate"|"existing"|"new", name: string, tag?: object|string}}
 */
export function describeTagSubmission(tags, value, applied = []) {
  const name = normalizeTagName(nameOf(value));
  if (name === "") {
    return { status: "empty", name: "" };
  }

  const existing = findTagByName(tags, name);
  // Submit the stored spelling verbatim, not what was typed, so "sci fi" does
  // not show up next to the "Sci Fi" it was meant to reuse. Not re-normalised:
  // the backend matches on the exact stored string, so collapsing the spaces of
  // a tag genuinely called "Sci  Fi" would create the duplicate this is here to
  // prevent. Normalising only applies to a name we are about to create.
  const canonicalName = existing ? nameOf(existing) : name;

  if (findTagByName(applied, canonicalName)) {
    return { status: "duplicate", name: canonicalName, tag: existing };
  }

  return existing
    ? { status: "existing", name: canonicalName, tag: existing }
    : { status: "new", name: canonicalName };
}
