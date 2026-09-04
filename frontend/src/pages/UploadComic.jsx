import { PageLayout } from "@/components/layout/PageLayout";

import UploadComicForm from "@/components/UploadComicForm";

export default function UploadComicPage() {
  return (
    <PageLayout width="form">
      <div className="flex flex-col items-center">
        <UploadComicForm />
      </div>
    </PageLayout>
  );
}
