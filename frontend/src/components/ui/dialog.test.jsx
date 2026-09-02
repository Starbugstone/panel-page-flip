import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
} from "./alert-dialog";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "./dialog";

const MOBILE_DIALOG_CLASSES = [
  "w-[calc(100%-2rem)]",
  "max-h-[calc(100dvh-2rem)]",
  "overflow-y-auto",
  "rounded-lg",
  "p-4",
  "sm:p-6",
];

describe("mobile dialog bounds", () => {
  it("keeps standard dialogs away from every viewport edge", () => {
    render(
      <Dialog open>
        <DialogContent>
          <DialogTitle>Edit comic</DialogTitle>
          <DialogDescription>Dialog content</DialogDescription>
        </DialogContent>
      </Dialog>,
    );

    expect(screen.getByRole("dialog")).toHaveClass(...MOBILE_DIALOG_CLASSES);
  });

  it("keeps confirmation dialogs away from every viewport edge", () => {
    render(
      <AlertDialog open>
        <AlertDialogContent>
          <AlertDialogTitle>Delete comic?</AlertDialogTitle>
          <AlertDialogDescription>Confirmation content</AlertDialogDescription>
        </AlertDialogContent>
      </AlertDialog>,
    );

    expect(screen.getByRole("alertdialog")).toHaveClass(...MOBILE_DIALOG_CLASSES);
  });
});
