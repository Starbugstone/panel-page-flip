import { PageLayout, PageHeader, PageLoading } from "@/components/layout/PageLayout";

import { useEffect, useRef } from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/hooks/use-auth";
import { Navigate, useSearchParams } from "react-router-dom";
import { AdminUsersList } from "@/components/AdminUsersList";
import { AdminComicsList } from "@/components/AdminComicsList";
import { AdminTagsList } from "@/components/AdminTagsList";
import { AdminSharesList } from "@/components/AdminSharesList";
import { AdminSharingCodesList } from "@/components/AdminSharingCodesList";
import { AdminOverview } from "@/components/AdminOverview";
import { AdminDropbox } from "@/components/AdminDropbox";
import { AdminAuditList } from "@/components/AdminAuditList";
import { AdminComicFormats } from "@/components/AdminComicFormats";
import { AdminMetadataProviders } from "@/components/AdminMetadataProviders";
import { AdminContentReports } from "@/components/AdminContentReports";

const TABS = ["overview", "pending", "users", "comics", "formats", "metadata", "tags", "shares", "sharing-codes", "content-reports", "dropbox", "audit"];

export default function AdminDashboard() {
  const { isAdmin, loading } = useAuth(); // Destructure loading state
  const [searchParams, setSearchParams] = useSearchParams();

  // The tab lives in the URL alongside each list's page and search, so coming
  // back from a user's detail page lands on the view that was left.
  const requestedTab = searchParams.get("tab");
  const activeTab = TABS.includes(requestedTab) ? requestedTab : "overview";
  const lastRequestedTab = useRef(activeTab);

  useEffect(() => {
    lastRequestedTab.current = activeTab;
  }, [activeTab]);

  const setActiveTab = (tab) => {
    // Radix can report the same pointer selection from mousedown and focus
    // before this controlled value has re-rendered. Treat it as one navigation
    // so Back does not have to cross two identical history entries. Syncing the
    // ref from the URL-derived value also keeps external navigation authoritative.
    if (lastRequestedTab.current === tab) return;
    lastRequestedTab.current = tab;

    setSearchParams((current) => {
      const next = new URLSearchParams(current);
      tab === "overview" ? next.delete("tab") : next.set("tab", tab);
      return next;
    });
  };

  if (loading) return <PageLayout><PageLoading label="Loading administration…" /></PageLayout>;

  // If loading is complete and user is not admin, redirect to dashboard
  if (!isAdmin) {
    return <Navigate to="/dashboard" replace />;
  }
  
  return (
    <PageLayout>
      <PageHeader title="Admin dashboard" description="Manage accounts, content, and your installation." />
      
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="mb-6 flex h-auto w-full justify-start overflow-x-auto">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="pending">Pending</TabsTrigger>
          <TabsTrigger value="users">Users</TabsTrigger>
          <TabsTrigger value="comics">Comics</TabsTrigger>
          <TabsTrigger value="formats">Formats</TabsTrigger>
          <TabsTrigger value="metadata">Metadata</TabsTrigger>
          <TabsTrigger value="tags">Tags</TabsTrigger>
          <TabsTrigger value="shares">Shares</TabsTrigger>
          <TabsTrigger value="sharing-codes">Sharing codes</TabsTrigger>
          <TabsTrigger value="content-reports">Content reports</TabsTrigger>
          <TabsTrigger value="dropbox">Dropbox</TabsTrigger>
          <TabsTrigger value="audit">Audit</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          <AdminOverview />
        </TabsContent>

        <TabsContent value="pending" className="space-y-6">
          <AdminUsersList showOnlyUnverified />
        </TabsContent>
        
        <TabsContent value="users" className="space-y-6">
          <AdminUsersList />
        </TabsContent>
        
        <TabsContent value="comics" className="space-y-6">
          <AdminComicsList />
        </TabsContent>

        <TabsContent value="formats" className="space-y-6">
          <AdminComicFormats />
        </TabsContent>

        <TabsContent value="metadata" className="space-y-6">
          <AdminMetadataProviders />
        </TabsContent>
        
        <TabsContent value="tags" className="space-y-6">
          <AdminTagsList />
        </TabsContent>

        <TabsContent value="shares" className="space-y-6">
          <AdminSharesList />
        </TabsContent>

        <TabsContent value="sharing-codes" className="space-y-6">
          <AdminSharingCodesList />
        </TabsContent>

        <TabsContent value="content-reports" className="space-y-6">
          <AdminContentReports />
        </TabsContent>

        <TabsContent value="dropbox" className="space-y-6">
          <AdminDropbox />
        </TabsContent>

        <TabsContent value="audit" className="space-y-6">
          <AdminAuditList />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}
