import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Card, CardHeader, CardTitle } from "./card";

describe("card heading hierarchy", () => {
  it("treats a card as a section beneath the page heading by default", () => {
    render(<><h1>Settings</h1><Card><CardHeader><CardTitle>Your account</CardTitle></CardHeader></Card></>);
    expect(screen.getByRole("heading", { level: 2, name: "Your account" })).toBeInTheDocument();
  });

  it("lets a card provide the page's primary heading", () => {
    render(<Card><CardHeader><CardTitle as="h1">Upload a comic</CardTitle></CardHeader></Card>);
    expect(screen.getByRole("heading", { level: 1, name: "Upload a comic" })).toBeInTheDocument();
  });
});
