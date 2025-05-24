import { useState, useCallback, useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { useToast } from "@/hooks/use-toast";
import { useTags } from "@/hooks/use-tags.jsx";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Upload, X, FileUp, CheckCircle, AlertCircle, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils.js";
import { Progress } from "@/components/ui/progress";

const UploadComicForm = () => {
  const { toast } = useToast();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { searchTags, addTagToCache } = useTags();
  
  const [file, setFile] = useState(null);
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [tagInput, setTagInput] = useState("");
  const [tags, setTags] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [currentChunk, setCurrentChunk] = useState(0);
  const [totalChunks, setTotalChunks] = useState(0);
  const [uploadStatus, setUploadStatus] = useState(null); // 'initializing', 'uploading', 'processing', 'complete', 'error'
  const [tagSuggestions, setTagSuggestions] = useState([]);
  const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const suggestionsRef = useRef(null);
  const tagInputRef = useRef(null);
  
  // Function to convert filename to readable title
  const generateTitleFromFilename = (filename) => {
    // Remove the .cbz extension
    let title = filename.replace(/\.cbz$/i, '');
    
    // Handle snake_case
    title = title.replace(/_/g, ' ');
    
    // Handle kebab-case
    title = title.replace(/-/g, ' ');
    
    // Handle camelCase and PascalCase
    // Insert space before uppercase letters that are preceded by lowercase or digits
    title = title.replace(/([a-z0-9])([A-Z])/g, '$1 $2');
    
    // Handle numbers with no spaces
    title = title.replace(/([a-zA-Z])([0-9])/g, '$1 $2');
    title = title.replace(/([0-9])([a-zA-Z])/g, '$1 $2');
    
    // Replace multiple spaces with a single space
    title = title.replace(/\s+/g, ' ');
    
    // Capitalize first letter of each word
    title = title.split(' ')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ');
    
    return title.trim();
  };
  
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
      const selectedFile = e.target.files[0];
      setFile(selectedFile);
      
      // Generate title from filename if title field is empty
      if (!title.trim()) {
        const generatedTitle = generateTitleFromFilename(selectedFile.name);
        setTitle(generatedTitle);
      }
    }
  };
  
  // Fetch tag suggestions based on input
  const fetchTagSuggestions = async (query) => {
    if (!query.trim() || query.trim().length < 2) {
      setTagSuggestions([]);
      setShowSuggestions(false);
      return;
    }

    setIsLoadingSuggestions(true);
    try {
      // Use the tag context to search for tags
      const results = await searchTags(query.trim());
      setTagSuggestions(results.map(tag => tag.name));
      setShowSuggestions(true);
    } catch (error) {
      console.error('Error fetching tag suggestions:', error);
      setTagSuggestions([]);
    } finally {
      setIsLoadingSuggestions(false);
    }
  };

  // Debounce function for tag suggestions
  useEffect(() => {
    const timeoutId = setTimeout(() => {
      fetchTagSuggestions(tagInput);
    }, 300);
    return () => clearTimeout(timeoutId);
  }, [tagInput]);

  // Close suggestions when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (suggestionsRef.current && !suggestionsRef.current.contains(event.target) && 
          tagInputRef.current && !tagInputRef.current.contains(event.target)) {
        setShowSuggestions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);
  
  // Tag management
  const addTag = () => {
    if (tagInput.trim() && !tags.includes(tagInput.trim())) {
      setTags([...tags, tagInput.trim()]);
      setTagInput("");
      setShowSuggestions(false);
    }
  };
  
  const removeTag = (tagToRemove) => {
    setTags(tags.filter(tag => tag !== tagToRemove));
  };
  
  const handleTagKeyDown = (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      addTag();
    } else if (e.key === "Escape") {
      setShowSuggestions(false);
    } else if (e.key === "ArrowDown" && showSuggestions && tagSuggestions.length > 0) {
      e.preventDefault();
      const suggestionElements = suggestionsRef.current?.querySelectorAll('button');
      if (suggestionElements?.length) suggestionElements[0].focus();
    }
  };
  
  const handleSuggestionKeyDown = (e, index) => {
    if (e.key === "Enter") {
      e.preventDefault();
      selectSuggestion(tagSuggestions[index]);
    } else if (e.key === "Escape") {
      setShowSuggestions(false);
      tagInputRef.current?.focus();
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      const suggestionElements = suggestionsRef.current?.querySelectorAll('button');
      if (suggestionElements?.length && index < suggestionElements.length - 1) {
        suggestionElements[index + 1].focus();
      }
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      const suggestionElements = suggestionsRef.current?.querySelectorAll('button');
      if (index > 0 && suggestionElements?.length) {
        suggestionElements[index - 1].focus();
      } else {
        tagInputRef.current?.focus();
      }
    }
  };

  const selectSuggestion = (suggestion) => {
    if (!tags.includes(suggestion)) {
      setTags([...tags, suggestion]);
      setTagInput("");
      setShowSuggestions(false);
      tagInputRef.current?.focus();
    }
  };
  
  // Helper to get CSRF token from cookies
  const getCsrfToken = () => {
    const cookies = document.cookie.split(';').reduce((acc, cookie) => {
      const [key, value] = cookie.trim().split('=');
      acc[key] = value;
      return acc;
    }, {});
    
    return cookies['XSRF-TOKEN'] || '';
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
    setUploadProgress(0);
    setUploadStatus('initializing');
    
    try {
      // Generate a unique file ID for this upload
      const fileId = `${Date.now()}-${Math.random().toString(36).substring(2, 15)}`;
      const chunkSize = 1 * 1024 * 1024; // 1MB chunks to stay well under the 2MB limit
      const chunks = Math.ceil(file.size / chunkSize);
      setTotalChunks(chunks);
      
      // Step 1: Initialize the upload
      const csrfToken = getCsrfToken();
      
      // Create metadata object for the comic
      const metadata = {
        title: title,
        author: author || '',
        publisher: '',
        description: '',
        tags: tags
      };
      
      const initResponse = await fetch('/api/comics/upload/init', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
          fileId: fileId,
          filename: file.name,
          totalChunks: chunks,
          metadata: metadata
        }),
        credentials: 'include'
      });
      
      if (!initResponse.ok) {
        const errorText = await initResponse.text();
        try {
          const errorData = JSON.parse(errorText);
          throw new Error(errorData.message || 'Failed to initialize upload');
        } catch (e) {
          throw new Error('Failed to initialize upload: Server returned an invalid response');
        }
      }
      
      setUploadStatus('uploading');
      
      // Step 2: Upload each chunk
      for (let chunkIndex = 0; chunkIndex < chunks; chunkIndex++) {
        setCurrentChunk(chunkIndex + 1);
        
        const start = chunkIndex * chunkSize;
        const end = Math.min(file.size, start + chunkSize);
        const chunk = file.slice(start, end);
        
        const formData = new FormData();
        formData.append('fileId', fileId);
        formData.append('chunkIndex', chunkIndex);
        formData.append('chunk', new Blob([chunk]));
        
        const chunkResponse = await fetch('/api/comics/upload/chunk', {
          method: 'POST',
          headers: {
            'X-XSRF-TOKEN': getCsrfToken(),
          },
          body: formData,
          credentials: 'include'
        });
        
        if (!chunkResponse.ok) {
          const errorText = await chunkResponse.text();
          try {
            const errorData = JSON.parse(errorText);
            throw new Error(errorData.message || `Failed to upload chunk ${chunkIndex + 1}`);
          } catch (e) {
            throw new Error(`Failed to upload chunk ${chunkIndex + 1}: Server returned an invalid response`);
          }
        }
        
        setUploadProgress(Math.round(((chunkIndex + 1) / chunks) * 80)); // 80% of progress for chunks
      }
      
      // Step 3: Complete the upload
      setUploadStatus('processing');
      
      // Send JSON data to complete the upload
      const completeResponse = await fetch('/api/comics/upload/complete', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify({
          fileId: fileId
        }),
        credentials: 'include'
      });
      
      if (!completeResponse.ok) {
        const errorText = await completeResponse.text();
        try {
          const errorData = JSON.parse(errorText);
          throw new Error(errorData.message || 'Failed to complete upload');
        } catch (e) {
          throw new Error('Failed to complete upload: Server returned an invalid response');
        }
      }
      
      setUploadProgress(100);
      setUploadStatus('complete');
      
      try {
        const result = await completeResponse.json();
        toast({
          title: "Upload successful",
          description: "Comic has been uploaded successfully"
        });
        
        // If we have new tags from the upload, add them to the cache
        if (result && result.comic && result.comic.tags) {
          result.comic.tags.forEach(tag => {
            if (typeof tag === 'object' && tag.id && tag.name) {
              addTagToCache(tag);
            }
          });
        }
        
        // Reset form
        setFile(null);
        setTitle('');
        setAuthor('');
        setTags([]);
        setTagInput('');
        setUploading(false);
        setUploadProgress(0);
        
        // Navigate to dashboard after a short delay to show the success message
        setTimeout(() => {
          navigate('/dashboard');
        }, 1500);
      } catch (error) {
        // Silent catch - we already have the success toast
        // Still navigate to dashboard
        setTimeout(() => {
          navigate('/dashboard');
        }, 1500);
      }
    } catch (error) {
      setUploadStatus('error');
      toast({
        title: "Upload failed",
        description: error.message || "An error occurred during upload",
        variant: "destructive"
      });
      setUploading(false);
    } finally {
      if (uploadStatus !== 'complete') {
        setUploading(false);
      }
    }
  };

  // Status display component
  const UploadStatus = () => {
    if (!uploadStatus) return null;
    
    const statusMessages = {
      initializing: 'Preparing upload...',
      uploading: `Uploading chunk ${currentChunk} of ${totalChunks}`,
      processing: 'Processing comic file...',
      complete: 'Upload complete!',
      error: 'Upload failed'
    };
    
    const statusIcons = {
      initializing: <FileUp className="animate-pulse" />,
      uploading: <FileUp className="animate-bounce" />,
      processing: <FileUp className="animate-spin" />,
      complete: <CheckCircle className="text-green-500" />,
      error: <AlertCircle className="text-red-500" />
    };
    
    return (
      <div className="mt-4 flex flex-col items-center space-y-2">
        <div className="flex items-center space-x-2">
          {statusIcons[uploadStatus]}
          <span>{statusMessages[uploadStatus]}</span>
        </div>
        <Progress value={uploadProgress} className="w-full" />
        <span className="text-xs text-muted-foreground">{uploadProgress}%</span>
      </div>
    );
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="flex flex-col items-center">
        <Card className="w-full max-w-xl">
          <CardHeader>
            <CardTitle className="text-2xl font-comic">Upload New Comic</CardTitle>
            <CardDescription>Share your favorite comics with the community</CardDescription>
          </CardHeader>
          <CardContent>
            <form id="upload-form" onSubmit={handleSubmit} className="space-y-5">
              <div className="space-y-2">
                <Label htmlFor="comic-file">Comic File (.cbz)</Label>
                <div 
                  className={`border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center ${!uploading ? 'cursor-pointer hover:border-gray-400 dark:hover:border-gray-500' : ''}`} 
                  onClick={() => !uploading && document.getElementById('comic-file').click()}
                >
                  <input 
                    id="comic-file" 
                    type="file" 
                    accept=".cbz" 
                    onChange={handleFileChange} 
                    className="hidden"
                    required
                    disabled={uploading}
                  />
                  {file ? (
                    <div className="flex items-center justify-center gap-2">
                      <span className="text-sm font-medium">{file.name}</span>
                      {!uploading && (
                        <Button 
                          type="button" 
                          variant="ghost" 
                          size="sm" 
                          className="p-0 h-auto" 
                          onClick={(e) => {
                            e.stopPropagation();
                            setFile(null);
                          }}
                        >
                          <X size={16} />
                        </Button>
                      )}
                    </div>
                  ) : (
                    <div className="flex flex-col items-center">
                      <Upload className="h-10 w-10 text-gray-400 mb-2" />
                      <span className="text-sm font-medium">Drag & drop or click to select a .cbz file</span>
                    </div>
                  )}
                </div>
              </div>
              
              {uploading && <UploadStatus />}
              
              <div className="space-y-2">
                <Label htmlFor="title">Title</Label>
                <Input 
                  id="title" 
                  value={title} 
                  onChange={(e) => setTitle(e.target.value)} 
                  placeholder="Comic Title"
                  required
                  disabled={uploading}
                />
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="author">Author (optional)</Label>
                <Input 
                  id="author" 
                  value={author} 
                  onChange={(e) => setAuthor(e.target.value)} 
                  placeholder="Author Name"
                  disabled={uploading}
                />
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="tags">Tags</Label>
                <div className="flex gap-2 relative">
                  <div className="flex-1 relative">
                    <Input 
                      ref={tagInputRef}
                      id="tags" 
                      value={tagInput} 
                      onChange={(e) => setTagInput(e.target.value)} 
                      placeholder="Add tags..."
                      className="flex-1 w-full"
                      disabled={uploading}
                      onKeyDown={handleTagKeyDown}
                      onFocus={() => tagInput.trim().length >= 2 && setShowSuggestions(true)}
                      autoComplete="off"
                      autoCorrect="off"
                      autoCapitalize="off"
                      spellCheck="false"
                      name="upload-tag-input-unique"
                    />
                    {isLoadingSuggestions && (
                      <div className="absolute right-2 top-1/2 transform -translate-y-1/2">
                        <Loader2 size={16} className="animate-spin text-muted-foreground" />
                      </div>
                    )}
                    {showSuggestions && tagSuggestions.length > 0 && !uploading && (
                      <div 
                        ref={suggestionsRef}
                        className="absolute z-10 mt-1 w-full bg-background border rounded-md shadow-lg max-h-60 overflow-auto"
                      >
                        <div className="py-1">
                          {tagSuggestions.map((suggestion, index) => (
                            <button
                              key={index}
                              type="button"
                              className={cn(
                                "w-full text-left px-4 py-2 text-sm hover:bg-accent focus:bg-accent focus:outline-none",
                                tags.includes(suggestion) ? "opacity-50" : ""
                              )}
                              onClick={() => selectSuggestion(suggestion)}
                              onKeyDown={(e) => handleSuggestionKeyDown(e, index)}
                              disabled={tags.includes(suggestion)}
                            >
                              {suggestion}
                              {tags.includes(suggestion) && " (already added)"}
                            </button>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                  <Button 
                    type="button" 
                    variant="outline" 
                    size="icon" 
                    onClick={addTag}
                    disabled={uploading || !tagInput.trim()}
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                      <path d="M5 12h14"></path>
                      <path d="M12 5v14"></path>
                    </svg>
                  </Button>
                </div>
                
                <div className="flex flex-wrap gap-2 mt-2">
                  {tags.map((tag, index) => (
                    <div key={index} className="bg-secondary text-secondary-foreground px-2 py-1 rounded-md flex items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-3 w-3">
                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                        <line x1="7" y1="7" x2="7.01" y2="7"></line>
                      </svg>
                      {tag}
                      {!uploading && (
                        <button 
                          type="button"
                          onClick={() => removeTag(tag)} 
                          className="ml-1 hover:text-destructive"
                        >
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-3 w-3">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                          </svg>
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            </form>
          </CardContent>
          <CardFooter className="flex justify-between">
            <Button variant="outline" onClick={() => navigate(-1)} disabled={uploading}>Cancel</Button>
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
      </div>
    </div>
  );
};

export default UploadComicForm;
