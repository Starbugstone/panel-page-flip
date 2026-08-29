/**
 * Public landing-page copy. The React page and the crawlable first render in
 * `frontend/index.html` both have to say these words: Symfony serves that HTML
 * to crawlers that never run JavaScript, and a heading that exists only in the
 * React tree is invisible to them.
 */
export const landingCopy = Object.freeze({
  badge: "Your collection, made readable",
  headlineLead: "Your comics,",
  headlineEmphasis: "ready when you are.",
  intro:
    "Turn scattered comic files into a personal library you can organize, enjoy on any screen, and share with the people who will love them too.",
  startLibrary: "Start your library",
  logIn: "Log in",
  formats: "CBZ, plus server-enabled CBR, CB7, CBT, and PDF support.",
  featuresEyebrow: "More than a file viewer",
  featuresHeading: "Make a collection feel collected.",
  featuresIntro:
    "Panel Page Flip adds the structure, continuity, and connection that a folder full of archives cannot.",
  library: Object.freeze({
    heading: "A library that feels like yours",
    body: "Build nested folders for series, publishers, reading lists, or whatever makes sense to you. Add tags, search the collection, and move comics in bulk without touching the original files.",
    points: Object.freeze([
      "Personal folders and tags",
      "Progress saved automatically",
      "Pick up on another device",
    ]),
  }),
  sharing: Object.freeze({
    heading: "Share the good stuff",
    body: "Invite a friend directly or send a private sharing code. They get their own place in the book and can organize it in their library, while you stay in control of access.",
    points: Object.freeze([
      "Share without duplicating the comic",
      "Each reader keeps their own progress",
      "Revoke access whenever you need to",
    ]),
  }),
  reader: Object.freeze({
    heading: "Built for the next page.",
    paragraphs: Object.freeze([
      "The reader does not hand your browser one giant comic file. The server opens the source and delivers each page individually, then chooses a size that matches the available space and your screen’s sharpness.",
      "Each result is right-sized for the screen and cached, so returning readers and popular pages skip repeat processing while access remains protected on every request.",
    ]),
    steps: Object.freeze([
      Object.freeze({
        number: "01",
        heading: "Open the source",
        body: "One reader handles archive and PDF formats through the same protected pipeline.",
      }),
      Object.freeze({
        number: "02",
        heading: "Request only nearby pages",
        body: "The reader prepares what is next and releases distant artwork to keep memory use in check.",
      }),
      Object.freeze({
        number: "03",
        heading: "Fit, cache, and keep reading",
        body: "Phones avoid oversized scans; larger screens and zoom get more detail when they need it.",
      }),
    ]),
  }),
  close: Object.freeze({
    heading: "Give your comics a better home.",
    body: "Organize the collection, share a favorite, and settle in with a reader designed to stay out of the story’s way.",
  }),
});

export const landingPreviewCopy = Object.freeze({
  nowReading: "Now reading",
  pageStatus: "Page 18 of 24",
  progress: "75%",
  cachedBadge: "Right-sized and cached",
});

export function landingPhrases(copy = landingCopy) {
  const phrases = [];
  const walk = (value) => {
    if (typeof value === "string") {
      phrases.push(value);
      return;
    }
    if (Array.isArray(value)) {
      value.forEach(walk);
      return;
    }
    if (value && typeof value === "object") {
      Object.values(value).forEach(walk);
    }
  };
  walk(copy);
  return phrases;
}
