import { Link } from "react-router-dom";
import { PageHeader, PageLayout } from "@/components/layout/PageLayout";
import { Button } from "@/components/ui/button";

export default function NotFound() {
  return (
    <PageLayout width="reading" className="flex min-h-[60vh] flex-col justify-center">
      <PageHeader title="404" description="This page could not be found. The link may have changed or the page may no longer exist." />
      <Button asChild className="self-start"><Link to="/">Return to Home</Link></Button>
    </PageLayout>
  );
}
