
import UploadComicForm from "@/components/UploadComicForm";

export default function UploadComicPage() {
  return (
    <div className="container mx-auto px-4 py-8">
      <div className="flex flex-col items-center">
        <h1 className="text-3xl font-comic mb-6">Upload New Comic</h1>
        <UploadComicForm />
      </div>
    </div>
  );
}
