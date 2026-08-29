/**
 * The vocabulary a metadata proposal is described in, and the pure sorting a
 * suggestion list needs before anything renders it.
 *
 * Kept out of the component because these are decisions about the data — what
 * a field is called, which tags are still worth offering — and the component
 * has enough to do arranging them.
 */

export const FIELD_LABELS = {
  title: "Title",
  series: "Series",
  issueNumber: "Issue",
  issueCount: "Issues in series",
  volume: "Volume",
  publisher: "Publisher",
  description: "Description",
  publishedAt: "Published",
  languageCode: "Language",
  ageRating: "Age rating",
  creators: "Credits",
};

export const SOURCE_LABELS = {
  comicinfo: "from the file",
  filename: "from the filename",
  provider: "from a provider",
  user: "yours",
};

export const CLASSIFICATION_LABELS = {
  characters: "Characters",
  teams: "Teams",
  locations: "Locations",
  storyArcs: "Story arcs",
};

/** Collapsed once there are enough genres to be a wall rather than a hint. */
export const VISIBLE_GENRE_LIMIT = 4;

export const fieldLabel = (field) => FIELD_LABELS[field] ?? field;
export const sourceLabel = (source) => SOURCE_LABELS[source] ?? source;

/** Credits are role → names; everything else is a scalar. */
export const summarise = (value) => {
  if (value === null || value === undefined || value === "") return "empty";
  if (Array.isArray(value)) return value.join(", ") || "empty";
  if (typeof value === "object") {
    const text = Object.entries(value)
      .map(([role, names]) => `${role}: ${(names ?? []).join(", ")}`)
      .join(" · ");
    return text.length > 60 ? `${text.slice(0, 60)}…` : text || "empty";
  }
  const text = String(value);
  return text.length > 60 ? `${text.slice(0, 60)}…` : text;
};

/**
 * Tags still worth offering, split by where they would go.
 *
 * A tag already on the comic is not a suggestion. Genres are separated from
 * tags the library already has because accepting a genre creates something,
 * and that is a different offer from reusing a name the user already chose.
 */
export const partitionTagSuggestions = (tagSuggestions, currentTags) => {
  const unused = tagSuggestions.filter(
    (tag) => !currentTags.some((name) => name.toLowerCase() === tag.name.toLowerCase())
  );

  return {
    unused,
    libraryTags: unused.filter((tag) => tag.kind !== "genre"),
    genreTags: unused.filter((tag) => tag.kind === "genre"),
  };
};

/** The classification fields that have anything to say, in label order. */
export const listClassification = (classification) => Object.entries(CLASSIFICATION_LABELS)
  .map(([field, label]) => [label, classification?.[field] ?? []])
  .filter(([, values]) => values.length > 0);
