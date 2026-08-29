/**
 * The organisation guide's content, as data.
 *
 * It was 160 lines of near-identical markup blocks; as lists the shape of each
 * section is stated once and a new example is one line.
 */

export const APP_FOLDER = "Apps/StarbugStoneComics";

/** Indent depth, label, and the tags that file or folder would produce. */
export const FOLDER_TREE = [
  { depth: 0, label: `📁 ${APP_FOLDER}/` },
  { depth: 1, label: "📄 Superman.cbz", tags: "Dropbox" },
  { depth: 1, label: "📁 superHero/" },
  { depth: 2, label: "📄 Batman.cbz", tags: "Dropbox, Super Hero" },
  { depth: 2, label: "📄 WonderWoman.cbz", tags: "Dropbox, Super Hero" },
  { depth: 1, label: "📁 Manga/" },
  { depth: 2, label: "📄 naruto.cbz", tags: "Dropbox, Manga" },
  { depth: 2, label: "📁 Anime/" },
  { depth: 3, label: "📄 blackCat.cbz", tags: "Dropbox, Manga, Anime" },
  { depth: 1, label: "📁 sci-fi/" },
  { depth: 2, label: "📁 space_opera/" },
  { depth: 3, label: "📄 Foundation.cbz", tags: "Dropbox, Sci Fi, Space Opera" },
];

export const NAMING_CONVENTIONS = [
  { name: "camelCase", example: 'superHero → "Super Hero"' },
  { name: "snake_case", example: 'space_opera → "Space Opera"' },
  { name: "kebab-case", example: 'sci-fi → "Sci Fi"' },
  { name: "UPPERCASE", example: 'MANGA → "Manga"' },
  { name: "PascalCase", example: 'ActionAdventure → "Action Adventure"' },
  { name: "Mixed", example: "Any combination works!" },
];

/** `note` marks the one entry that states a limit rather than advice. */
export const BEST_PRACTICES = [
  { text: "Use descriptive folder names that make sense as tags" },
  { text: "Nest folders for hierarchical organization (Genre → Subgenre)" },
  { text: "Keep folder names concise but meaningful" },
  { text: "Use consistent naming conventions within your collection" },
  { text: 'Files in the root folder only get the "Dropbox" tag', note: true },
];

export const ORGANISATION_EXAMPLES = [
  { title: "By Genre:", folders: ["📁 Action/", "📁 Comedy/", "📁 Drama/", "📁 Fantasy/", "📁 Horror/"] },
  { title: "By Publisher:", folders: ["📁 Marvel/", "📁 DC_Comics/", "📁 Image/", "📁 Dark_Horse/"] },
  { title: "By Series:", folders: ["📁 Batman/", "📁 Spider-Man/", "📁 X-Men/", "📁 Walking_Dead/"] },
  { title: "Mixed Approach:", folders: ["📁 Marvel/superHero/", "📁 Manga/Action/", "📁 Indie/sci-fi/"] },
];
