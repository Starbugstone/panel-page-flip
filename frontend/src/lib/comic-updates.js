/**
 * The payload for saving an edited comic.
 *
 * Extracted from the dashboard because it is the one place a field can be
 * silently lost: the edit form grew structured metadata, this did not, and an
 * accepted suggestion was staged, saved, and dropped without a word. Listing
 * the fields here means a missing one is a failing test rather than a value
 * that quietly never arrives.
 */
export function buildComicUpdatePayload(comic) {
  return {
    id: comic.id,
    changes: {
      title: comic.title,
      author: comic.author,
      publisher: comic.publisher,
      description: comic.description,
      tags: comic.tags,
      explicitContent: comic.explicitContent === true,
      // An emptied field means "no value", which has to reach the server as
      // null rather than as undefined — undefined disappears in JSON, and the
      // server reads an absent key as "leave this alone".
      series: comic.series ?? null,
      issueNumber: comic.issueNumber ?? null,
      volume: comic.volume ?? null,
      publishedAt: comic.publishedAt ?? null,
    },
  };
}
