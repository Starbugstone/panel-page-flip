import { useState } from "react";
import { useTags } from "@/hooks/use-tags.jsx";
import { useToast } from "@/hooks/use-toast.js";
import { describeTagSubmission } from "@/lib/tag-suggestions.js";

const blankToNull = (value) => (String(value ?? "").trim() === "" ? null : String(value).trim());

function initialStructured(comic) {
  return {
    series: comic?.series ?? "", issueNumber: comic?.issueNumber ?? "",
    issueCount: comic?.issueCount ?? "", volume: comic?.volume ?? "",
    publishedAt: comic?.publishedAt ?? "", languageCode: comic?.languageCode ?? "",
    ageRating: comic?.ageRating ?? "",
  };
}

/** Owns the draft and persistence workflow; the dialog only composes fields. */
export function useComicEditForm(comic, onSave, onClose) {
  const [fields, setFields] = useState({ title: comic?.title || "", author: comic?.author || "", publisher: comic?.publisher || "", description: comic?.description || "" });
  const [structured, setStructured] = useState(() => initialStructured(comic));
  const [tags, setTags] = useState(comic?.tags || []);
  const [explicitContent, setExplicitContent] = useState(comic?.explicitContent === true);
  const [creators, setCreators] = useState(comic?.creators ?? null);
  const [metadataOrigin, setMetadataOrigin] = useState(comic?.metadataOrigin ?? null);
  const [newTag, setNewTag] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();
  const { tags: availableTags, addTagToCache } = useTags();
  const tagSubmission = describeTagSubmission(availableTags, newTag, tags);

  const setField = (field, value) => setFields((current) => ({ ...current, [field]: value }));
  const setStructuredField = (field, value) => setStructured((current) => ({ ...current, [field]: value }));
  const addTag = (name) => {
    const resolved = name ?? tagSubmission.name;
    if (!resolved || tags.some((tag) => tag.toLowerCase() === resolved.toLowerCase())) return;
    setTags((current) => [...current, resolved]);
    setNewTag("");
  };
  const acceptSuggestion = (patch) => Object.entries(patch).forEach(([field, value]) => {
    if (["title", "publisher", "description"].includes(field)) setField(field, String(value));
    else if (field === "creators") setCreators(value);
    else if (field === "metadataProvider") setMetadataOrigin((current) => ({ ...(current ?? {}), provider: String(value) }));
    else if (field === "metadataExternalId") setMetadataOrigin((current) => ({ ...(current ?? {}), externalId: String(value) }));
    else setStructuredField(field, String(value));
  });

  const submit = async () => {
    if (!fields.title.trim()) {
      toast({ title: "Title required", description: "Please provide a title for the comic.", variant: "destructive" });
      return;
    }
    setIsSubmitting(true);
    try {
      const result = await onSave({
        id: comic.id, ...fields, tags, explicitContent,
        ...Object.fromEntries(Object.entries(structured).map(([key, value]) => [key, blankToNull(value)])),
        creators: creators ?? undefined,
        metadataProvider: metadataOrigin?.provider ?? undefined,
        metadataExternalId: metadataOrigin?.externalId ?? undefined,
      });
      result?.tags?.forEach((tag) => {
        if (typeof tag === "object" && tag.id && tag.name) addTagToCache(tag);
      });
      toast({ title: "Comic updated", description: "Comic details have been updated successfully." });
      onClose();
    } catch (error) {
      toast({ title: "Update failed", description: error.message || "Failed to update comic details.", variant: "destructive" });
    } finally {
      setIsSubmitting(false);
    }
  };

  return {
    fields, setField, structured, setStructuredField, tags,
    removeTag: (tagToRemove) => setTags((current) => current.filter((tag) => tag !== tagToRemove)),
    explicitContent, setExplicitContent, metadataOrigin, newTag, setNewTag,
    availableTags, canAddTag: ["existing", "new"].includes(tagSubmission.status),
    addTag, acceptSuggestion, isSubmitting, submit,
  };
}
