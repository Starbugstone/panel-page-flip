
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { useToast } from "@/hooks/use-toast";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Upload, X, Tag as TagIcon, Plus } from "lucide-react";
import { Badge } from "@/components/ui/badge";

const UploadComicForm = () => {
  const { toast } = useToast();
  const navigate = useNavigate();
  const { user } = useAuth();
  
  const [file, setFile] = useState(null);
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [publisher, setPublisher] = useState("");
  const [description, setDescription] = useState("");
  const [uploading, setUploading] = useState(false);
  const [tags, setTags] = useState([]);
  const [tagInput, setTagInput] = useState("");
  
  const handleFileChange = (e) => {
    if (e.target.files[0]) {
      // Only accept .cbz files
      if (!e.target.files[0].name.toLowerCase().endsWith('.cbz')) {
        toast({
          title: "Invalid file",
          description: "Please upload a .cbz file",
          variant: "destructive"
        });
        return;
      }
      setFile(e.target.files[0]);
    }
  };
  
  const addTag = () => {
    if (tagInput && !tags.includes(tagInput.trim())) {
      setTags([...tags, tagInput.trim()]);
      setTagInput("");
    }
  };
  
  const removeTag = (tagToRemove) => {
    setTags(tags.filter(tag => tag !== tagToRemove));
  };
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!file || !title) {
      toast({
        title: "Missing information",
        description: "Please provide a title and select a file",
        variant: "destructive"
      });
      return;
    }
    
    setUploading(true);
    
    // This would be implemented to actually upload the file
    // For now, we'll mock a successful upload
    try {
      // Mock API call with a timeout
      await new Promise(resolve => setTimeout(resolve, 1000));
      
      toast({
        title: "Upload successful",
        description: "Your comic has been uploaded",
      });
      
      // Redirect to dashboard
      navigate("/dashboard");
    } catch (error) {
      toast({
        title: "Upload failed",
        description: error.message || "Something went wrong",
        variant: "destructive"
      });
    } finally {
      setUploading(false);
    }
  };
  
  return (
    <Card className="w-full max-w-xl">
      <CardHeader>
        <CardTitle className="text-2xl font-comic">Upload New Comic</CardTitle>
        <CardDescription>Share your favorite comics with the community</CardDescription>
      </CardHeader>
      <CardContent>
        <form id="upload-form" onSubmit={handleSubmit} className="space-y-5">
          <div className="space-y-2">
            <Label htmlFor="comic-file">Comic File (.cbz)</Label>
            <div className="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center cursor-pointer hover:border-gray-400 dark:hover:border-gray-500" onClick={() => document.getElementById('comic-file').click()}>
              <input 
                id="comic-file" 
                type="file" 
                accept=".cbz" 
                onChange={handleFileChange} 
                className="hidden"
                required
              />
              {file ? (
                <div className="flex items-center justify-center gap-2">
                  <span className="text-sm font-medium">{file.name}</span>
                  <Button 
                    variant="ghost" 
                    size="sm" 
                    onClick={(e) => {
                      e.stopPropagation();
                      setFile(null);
                    }}
                  >
                    <X size={16} />
                  </Button>
                </div>
              ) : (
                <div className="flex flex-col items-center">
                  <Upload className="h-10 w-10 text-gray-400 mb-2" />
                  <span className="text-sm font-medium">Drag & drop or click to select a .cbz file</span>
                </div>
              )}
            </div>
          </div>
          
          <div className="space-y-2">
            <Label htmlFor="title">Title</Label>
            <Input 
              id="title" 
              value={title} 
              onChange={(e) => setTitle(e.target.value)} 
              placeholder="Comic Title" 
              required
            />
          </div>
          
          <div className="space-y-2">
            <Label htmlFor="author">Author (optional)</Label>
            <Input 
              id="author" 
              value={author} 
              onChange={(e) => setAuthor(e.target.value)} 
              placeholder="Author Name"
            />
          </div>
          
          <div className="space-y-2">
            <Label htmlFor="publisher">Publisher (optional)</Label>
            <Input 
              id="publisher" 
              value={publisher} 
              onChange={(e) => setPublisher(e.target.value)} 
              placeholder="Publisher Name"
            />
          </div>
          
          <div className="space-y-2">
            <Label htmlFor="description">Description (optional)</Label>
            <Textarea 
              id="description" 
              value={description} 
              onChange={(e) => setDescription(e.target.value)} 
              placeholder="Brief description of the comic"
              rows={3}
            />
          </div>
          
          <div className="space-y-2">
            <Label htmlFor="tags">Tags</Label>
            <div className="flex gap-2">
              <Input 
                id="tags" 
                value={tagInput} 
                onChange={(e) => setTagInput(e.target.value)} 
                placeholder="Add tags..."
                className="flex-1"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    addTag();
                  }
                }}
              />
              <Button 
                type="button" 
                variant="outline" 
                size="icon" 
                onClick={addTag}
              >
                <Plus size={18} />
              </Button>
            </div>
            
            <div className="flex flex-wrap gap-2 mt-2">
              {tags.map((tag, index) => (
                <Badge key={index} variant="secondary" className="flex items-center gap-1">
                  <TagIcon size={14} />
                  {tag}
                  <button 
                    type="button"
                    onClick={() => removeTag(tag)} 
                    className="ml-1 hover:text-destructive"
                  >
                    <X size={14} />
                  </button>
                </Badge>
              ))}
            </div>
          </div>
        </form>
      </CardContent>
      <CardFooter className="flex justify-between">
        <Button variant="outline" onClick={() => navigate(-1)}>Cancel</Button>
        <Button 
          type="submit" 
          form="upload-form" 
          disabled={uploading} 
          className="space-x-2"
        >
          {uploading ? "Uploading..." : "Upload Comic"}
          <Upload size={16} />
        </Button>
      </CardFooter>
    </Card>
  );
};

export default UploadComicForm;
