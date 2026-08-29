import { Plus, Tag as TagIcon, X } from "lucide-react";
import { MetadataSuggestions } from "@/components/MetadataSuggestions";
import { TagBadge } from "@/components/TagBadge";
import { TagCombobox } from "@/components/TagCombobox";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { EXPLICIT_FLAG_DESCRIPTION, EXPLICIT_FLAG_LABEL } from "@/lib/sharing.js";

function TextField({ id, label, value, onChange, ...props }) {
  return <div className="grid gap-2"><Label htmlFor={id}>{label}</Label><Input id={id} value={value} onChange={(event) => onChange(event.target.value)} {...props} /></div>;
}

export function ComicEditDialogBody({ comic, form }) {
  return (
    <div className="-mx-6 grid min-h-0 flex-1 gap-4 overflow-y-auto px-6 py-4">
      <TextField id="title" label="Title *" value={form.fields.title} onChange={(value) => form.setField("title", value)} placeholder="Comic title" required />
      <TextField id="author" label="Author" value={form.fields.author} onChange={(value) => form.setField("author", value)} placeholder="Comic author" />
      <TextField id="publisher" label="Publisher" value={form.fields.publisher} onChange={(value) => form.setField("publisher", value)} placeholder="Comic publisher" />
      <div className="grid gap-2"><Label htmlFor="description">Description</Label><Textarea id="description" value={form.fields.description} onChange={(event) => form.setField("description", event.target.value)} placeholder="Comic description" rows={3} /></div>
      <div className="grid grid-cols-2 gap-3">
        <TextField id="series" label="Series" value={form.structured.series} onChange={(value) => form.setStructuredField("series", value)} placeholder="Series name" />
        <TextField id="issueNumber" label="Issue" value={form.structured.issueNumber} onChange={(value) => form.setStructuredField("issueNumber", value)} placeholder="7" />
        <TextField id="volume" label="Volume" value={form.structured.volume} onChange={(value) => form.setStructuredField("volume", value)} placeholder="1996" />
        <TextField id="publishedAt" label="Published" type="date" value={form.structured.publishedAt} onChange={(value) => form.setStructuredField("publishedAt", value)} />
      </div>
      <MetadataSuggestions comicId={comic?.id} onAccept={form.acceptSuggestion} onAddTag={form.addTag} currentTags={form.tags} staged={{ ...form.structured, title: form.fields.title }} metadataOrigin={form.metadataOrigin} />
      <div className="grid gap-2">
        <Label>Tags</Label>
        <div className="mb-2 flex flex-wrap gap-2">{form.tags.map((tag) => <TagBadge key={tag} tag={form.availableTags.find((item) => item.name === tag) || tag} className="flex items-center gap-1"><TagIcon size={12} />{tag}<Button type="button" variant="ghost" size="sm" className="ml-1 h-4 w-4 p-0" onClick={() => form.removeTag(tag)}><X size={12} /></Button></TagBadge>)}</div>
        <div className="relative flex gap-2"><TagCombobox value={form.newTag} onChange={form.setNewTag} onSubmit={form.addTag} applied={form.tags} className="flex-1" /><Button type="button" size="sm" onClick={() => form.addTag()} disabled={!form.canAddTag}><Plus size={16} className="mr-1" />Add</Button></div>
      </div>
      <div className="flex items-start gap-3 rounded-md border p-3">
        <Checkbox id="explicit-content" checked={form.explicitContent} onCheckedChange={(checked) => form.setExplicitContent(checked === true)} className="mt-0.5" />
        <div className="grid gap-1"><Label htmlFor="explicit-content" className="cursor-pointer">{EXPLICIT_FLAG_LABEL}</Label><p className="text-xs text-muted-foreground">{EXPLICIT_FLAG_DESCRIPTION}</p></div>
      </div>
    </div>
  );
}
