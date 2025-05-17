
import { useState } from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/hooks/use-auth";
import { Navigate } from "react-router-dom";
import { AdminUsersList } from "@/components/AdminUsersList";
import { AdminComicsList } from "@/components/AdminComicsList";
import { AdminTagsList } from "@/components/AdminTagsList";

export default function AdminDashboard() {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState("users");
  
  // If user is not admin, redirect to dashboard
  if (!user || !user.roles || !user.roles.includes("ROLE_ADMIN")) {
    return <Navigate to="/dashboard" />;
  }
  
  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-3xl font-comic mb-8">Admin Dashboard</h1>
      
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="users">Users</TabsTrigger>
          <TabsTrigger value="comics">Comics</TabsTrigger>
          <TabsTrigger value="tags">Tags</TabsTrigger>
        </TabsList>
        
        <TabsContent value="users" className="space-y-6">
          <AdminUsersList />
        </TabsContent>
        
        <TabsContent value="comics" className="space-y-6">
          <AdminComicsList />
        </TabsContent>
        
        <TabsContent value="tags" className="space-y-6">
          <AdminTagsList />
        </TabsContent>
      </Tabs>
    </div>
  );
}
