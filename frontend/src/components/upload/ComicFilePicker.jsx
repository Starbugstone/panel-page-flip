import { useId, useRef, useState } from "react";
import { Upload } from "lucide-react";
import { Button } from "@/components/ui/button";
import { comicFileAccept } from "@/lib/comic-upload";
import { cn } from "@/lib/utils";

export function ComicFilePicker({ id, formats, multiple = false, disabled = false, onFiles, children }) {
  const generatedId = useId();
  const inputRef = useRef(null);
  const [dragging, setDragging] = useState(false);

  return (
    <div
      role="group"
      aria-label="Comic file selection"
      data-dragging={dragging && !disabled}
      className={cn("rounded-xl border-2 border-dashed border-input p-4 text-center sm:p-6", dragging && !disabled && "border-primary bg-primary/5", disabled && "opacity-60")}
      onDragEnter={(event) => { event.preventDefault(); if (!disabled) setDragging(true); }}
      onDragOver={(event) => event.preventDefault()}
      onDragLeave={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setDragging(false); }}
      onDrop={(event) => {
        event.preventDefault();
        setDragging(false);
        if (!disabled && event.dataTransfer.files.length > 0) onFiles(Array.from(event.dataTransfer.files));
      }}
    >
      <input
        id={id ?? generatedId}
        ref={inputRef}
        aria-label={id ? undefined : "Comic files"}
        type="file"
        multiple={multiple}
        accept={comicFileAccept(formats)}
        className="hidden"
        disabled={disabled}
        onChange={(event) => {
          const files = Array.from(event.target.files);
          if (files.length > 0) onFiles(files);
          event.target.value = "";
        }}
      />
      <Upload aria-hidden="true" className="mx-auto mb-3 h-8 w-8 text-primary" />
      {children ?? <p className="mb-3 text-sm text-muted-foreground">Drag and drop supported comic files here</p>}
      <Button type="button" variant="outline" className="mt-3" disabled={disabled} onClick={() => inputRef.current?.click()}>
        {multiple ? "Choose files" : "Choose file"}
      </Button>
    </div>
  );
}
