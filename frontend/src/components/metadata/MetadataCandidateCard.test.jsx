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
  coverUrl: "https://example.test/cover.jpg",
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
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  /* One field is already a single button away, so a second button offering to
     take "all" of it would only be a way to press the wrong one. */
  it("offers to take every field only when there is more than one", async () => {
    const onAcceptAll = vi.fn();
    const { unmount } = renderCard({ fields: [field("series")], onAcceptAll });
    expect(screen.queryByRole("button", { name: /use all/i })).not.toBeInTheDocument();
    unmount();

    renderCard({ onAcceptAll });
    await userEvent.click(screen.getByRole("button", { name: "Use all 2 fields" }));

    expect(onAcceptAll).toHaveBeenCalledTimes(1);
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

    expect(document.querySelector("img")).toHaveAttribute("src", "https://example.test/cover.jpg");
    expect(screen.getByRole("button", { name: "Show everything" })).toBeInTheDocument();
  });

  it("leaves the art out when the provider sent none", () => {
    renderCard({ showCover: true, candidate: candidate({ coverUrl: null }) });

    expect(document.querySelector("img")).toBeNull();
    expect(screen.getByText("Batman #7 — The Long Halloween")).toBeInTheDocument();
  });
});
