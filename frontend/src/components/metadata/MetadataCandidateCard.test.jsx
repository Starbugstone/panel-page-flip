import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { MetadataCandidateCard } from "./MetadataCandidateCard";

const candidate = (overrides = {}) => ({
  provider: "metron",
  externalId: "1",
  series: "Batman",
  issueNumber: "7",
  title: "The Long Halloween",
  publisher: "DC Comics",
  publishedAt: "1997-06-01",
  confidence: "exact",
  coverUrl: "https://static.metron.cloud/media/issue/cover.jpg",
  ...overrides,
});

const field = (name) => ({ field: name, suggested: name, source: "provider" });

const renderField = (suggestion, key) => <p key={key} data-testid={key}>{suggestion.field}</p>;

const renderCard = (props = {}) =>
  render(
    <MetadataCandidateCard
      candidate={candidate()}
      fields={[field("series"), field("title")]}
      fieldKey="metron-1"
      renderField={renderField}
      onAcceptAll={() => {}}
      {...props}
    />
  );

describe("a metadata candidate card", () => {
  it("heads the record with what the provider called it", () => {
    renderCard();

    expect(screen.getByText("Batman #7 — The Long Halloween")).toBeInTheDocument();
    expect(screen.getByText("DC Comics · 1997-06-01 · metron")).toBeInTheDocument();
    expect(screen.getByText("Exact match")).toBeInTheDocument();
  });

  /* A provider is free to send a confidence this build has never heard of, and
     the badge has to stay readable rather than render an empty box. */
  it("shows an unrecognised confidence as the provider spelled it", () => {
    renderCard({ candidate: candidate({ confidence: "speculative" }) });

    expect(screen.getByText("speculative")).toBeInTheDocument();
  });

  it("keys each field it was given so a list of them stays stable", () => {
    renderCard();

    expect(screen.getByTestId("metron-1-0")).toHaveTextContent("series");
    expect(screen.getByTestId("metron-1-1")).toHaveTextContent("title");
  });

  it("says so when the record would change nothing", () => {
    renderCard({ fields: [] });

    expect(screen.getByText("Matches what you already have.")).toBeInTheDocument();
  });

  /* Choosing a record is what a later refresh asks about, so a record agreeing
     with every field is as choosable as one disagreeing with all of them. These
     used to offer no way to choose them at all. */
  it.each([
    ["no field", []],
    ["one field", [field("series")]],
  ])("lets a record differing in %s be chosen", async (_label, fields) => {
    const onAcceptAll = vi.fn();
    renderCard({ fields, onAcceptAll });

    await userEvent.click(screen.getByRole("button", { name: "Use this record" }));

    expect(onAcceptAll).toHaveBeenCalledTimes(1);
  });

  it("says how many fields choosing a record would take", async () => {
    const onAcceptAll = vi.fn();
    renderCard({ onAcceptAll });

    await userEvent.click(screen.getByRole("button", { name: "Use all 2 fields" }));

    expect(onAcceptAll).toHaveBeenCalledTimes(1);
  });

  it("shows which record is chosen and does not offer it again", () => {
    renderCard({ recordChosen: true });

    expect(screen.getByRole("button", { name: "Record chosen" })).toBeDisabled();
  });

  /* The two things the search results and the expanded record disagree about.
     They were separate copies of this markup, and drifted. */
  it("shows the cover art and extra actions only when asked to", () => {
    const { unmount } = renderCard();
    expect(screen.queryByRole("presentation")).not.toBeInTheDocument();
    expect(document.querySelector("img")).toBeNull();
    expect(screen.queryByRole("button", { name: "Show everything" })).not.toBeInTheDocument();
    unmount();

    renderCard({ showCover: true, actions: <button type="button">Show everything</button> });

    expect(document.querySelector("img")).toHaveAttribute("src", "https://static.metron.cloud/media/issue/cover.jpg");
    expect(screen.getByRole("button", { name: "Show everything" })).toBeInTheDocument();
  });

  /* The page the cover is drawn on is the reader's business, not the host's. */
  it("does not tell the image host which page asked", () => {
    renderCard({ showCover: true });

    expect(document.querySelector("img")).toHaveAttribute("referrerpolicy", "no-referrer");
  });

  it.each([
    ["sent none", null],
    ["sent one the policy would block", "https://example.test/cover.jpg"],
    ["sent an insecure one", "http://static.metron.cloud/media/issue/cover.jpg"],
  ])("leaves the art out when the provider %s", (_label, coverUrl) => {
    renderCard({ showCover: true, candidate: candidate({ coverUrl }) });

    expect(document.querySelector("img")).toBeNull();
    expect(screen.getByText("Batman #7 — The Long Halloween")).toBeInTheDocument();
  });
});
