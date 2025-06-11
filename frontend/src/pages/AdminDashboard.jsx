
import { useState, useEffect } from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/hooks/use-auth";
import { Navigate, useSearchParams } from "react-router-dom";
import { AdminUsersList } from "@/components/AdminUsersList";
import { AdminComicsList } from "@/components/AdminComicsList";
import { AdminTagsList } from "@/components/AdminTagsList";
import DeploymentStatus from "@/components/DeploymentStatus";
import RollbackManagement from "./RollbackManagement";

export default function AdminDashboard() {
  const { user, loading } = useAuth(); // Destructure loading state
  const [searchParams] = useSearchParams();
  const [activeTab, setActiveTab] = useState("users");
  const [deploymentView, setDeploymentView] = useState("status"); // "status" or "management"

  // Check URL parameters for initial tab
  useEffect(() => {
    const tab = searchParams.get('tab');
    if (tab && ['users', 'comics', 'tags', 'deployment'].includes(tab)) {
      setActiveTab(tab);
    }
  }, [searchParams]);

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
    // console.log("AdminDashboard: Redirecting. User object after loading:", user); // For debugging
    return <Navigate to="/dashboard" replace />;
  }
  
  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-3xl font-comic mb-8">Admin Dashboard</h1>
      
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="users">Users</TabsTrigger>
          <TabsTrigger value="comics">Comics</TabsTrigger>
          <TabsTrigger value="tags">Tags</TabsTrigger>
          <TabsTrigger value="deployment">Deployment</TabsTrigger>
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
        
        <TabsContent value="deployment" className="space-y-6">
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-2xl font-semibold">Deployment Management</h2>
            <div className="flex gap-2">
              <button
                onClick={() => setDeploymentView("status")}
                className={`px-3 py-1 rounded text-sm ${
                  deploymentView === "status" 
                    ? "bg-blue-500 text-white" 
                    : "bg-gray-200 text-gray-700 hover:bg-gray-300"
                }`}
              >
                Status & Quick Actions
              </button>
              <button
                onClick={() => setDeploymentView("management")}
                className={`px-3 py-1 rounded text-sm ${
                  deploymentView === "management" 
                    ? "bg-blue-500 text-white" 
                    : "bg-gray-200 text-gray-700 hover:bg-gray-300"
                }`}
              >
                Full Management
              </button>
            </div>
          </div>
          
          {deploymentView === "status" ? (
            <DeploymentStatus />
          ) : (
            <RollbackManagement />
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
