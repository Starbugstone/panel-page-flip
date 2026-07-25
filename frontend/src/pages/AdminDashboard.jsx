
import { useState } from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/hooks/use-auth";
import { Navigate } from "react-router-dom";
import { AdminUsersList } from "@/components/AdminUsersList";
import { AdminComicsList } from "@/components/AdminComicsList";
import { AdminTagsList } from "@/components/AdminTagsList";
import { AdminOverview } from "@/components/AdminOverview";
import { AdminDropbox } from "@/components/AdminDropbox";
import { AdminAuditList } from "@/components/AdminAuditList";

export default function AdminDashboard() {
  const { user, loading } = useAuth(); // Destructure loading state
  const [activeTab, setActiveTab] = useState("overview");

  // Display a loading message while authentication is in progress
  if (loading) {
    return (
      <div className="container mx-auto px-4 py-8 flex justify-center items-center min-h-[calc(100vh-200px)]">
        <div className="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-primary"></div>
      </div>
    );
  }
  
  // If loading is complete and user is not admin, redirect to dashboard
  if (!user || !user.roles || !user.roles.includes("ROLE_ADMIN")) {
    return <Navigate to="/dashboard" replace />;
  }
  
  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-3xl font-comic mb-8">Admin Dashboard</h1>
      
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="pending">Pending</TabsTrigger>
          <TabsTrigger value="users">Users</TabsTrigger>
          <TabsTrigger value="comics">Comics</TabsTrigger>
          <TabsTrigger value="tags">Tags</TabsTrigger>
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
        
        <TabsContent value="tags" className="space-y-6">
          <AdminTagsList />
        </TabsContent>

        <TabsContent value="dropbox" className="space-y-6">
          <AdminDropbox />
        </TabsContent>

        <TabsContent value="audit" className="space-y-6">
          <AdminAuditList />
        </TabsContent>
      </Tabs>
    </div>
  );
}
