import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, expect, it } from "vitest";

import BulkUploadGate from "@/pages/BulkUploadGate.jsx";

const renderGate = (entry = "/upload/bulk") => render(
  <MemoryRouter initialEntries={[entry]}>
    <Routes>
      <Route path="/upload/bulk" element={<BulkUploadGate />} />
      <Route path="/upload/bulk/session" element={<h1>Bulk upload comics</h1>} />
      <Route path="/upload" element={<h1>Upload New Comic</h1>} />
    </Routes>
  </MemoryRouter>
);

describe("the AdSense Offerwall target", () => {
  it("does not claim to request or verify a rewarded advertisement", () => {
    renderGate();

    expect(screen.getByRole("heading", { name: "Bulk upload" })).toBeInTheDocument();
    expect(screen.getByText(/Google presents and completes it/i)).toBeInTheDocument();
    expect(screen.getByText(/does not receive or record an advertisement-completion signal/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /watch ad/i })).not.toBeInTheDocument();
  });

  it("always allows the user to continue when Google shows no Offerwall", async () => {
    renderGate();

    await userEvent.click(screen.getByRole("link", { name: "Continue to bulk upload" }));

    expect(screen.getByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("keeps the upload batch ad-free on its separate route", async () => {
    renderGate();

    const continuation = screen.getByRole("link", { name: "Continue to bulk upload" });
    expect(continuation).toHaveAttribute("href", "/upload/bulk/session");
    await userEvent.click(continuation);

    expect(screen.queryByText(/Google AdSense Offerwall/i)).not.toBeInTheDocument();
  });

  it("carries the chosen destination folder to either uploader", () => {
    renderGate("/upload/bulk?folder=7");

    expect(screen.getByRole("link", { name: "Continue to bulk upload" }))
      .toHaveAttribute("href", "/upload/bulk/session?folder=7");
    expect(screen.getByRole("link", { name: "Use single upload instead" }))
      .toHaveAttribute("href", "/upload?folder=7");
  });
});
