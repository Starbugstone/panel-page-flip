
import { Toaster } from "@/components/ui/toaster.jsx";
import { Toaster as Sonner } from "@/components/ui/sonner.jsx";
import { TooltipProvider } from "@/components/ui/tooltip.jsx";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { ThemeProvider } from "@/components/ThemeProvider.jsx";
import { Header } from "@/components/Header.jsx";
import { AuthProvider, useAuth } from "./hooks/use-auth.jsx";
import { TagProvider } from "./hooks/use-tags.jsx";
import Landing from "./pages/Landing.jsx";
import Login from "./pages/Login.jsx";
import Dashboard from "./pages/Dashboard.jsx";
import UploadComic from "./pages/UploadComic.jsx";
import ComicReader from "./pages/ComicReader.jsx";
import AdminDashboard from "./pages/AdminDashboard.jsx";
import ForgotPassword from "./pages/ForgotPassword.jsx";
import ResetPassword from "./pages/ResetPassword.jsx";
import NotFound from "./pages/NotFound.jsx";

const queryClient = new QueryClient();

// Protected route component
const ProtectedRoute = ({ children }) => {
  const { isAuthenticated, loading } = useAuth();
  
  if (loading) {
    return <div className="flex items-center justify-center h-screen">Loading...</div>;
  }
  
  if (!isAuthenticated) {
    return <Navigate to="/login" />;
  }
  
  return children;
};

// Admin route component
const AdminRoute = ({ children }) => {
  const { isAuthenticated, loading, user } = useAuth();
  
  if (loading) {
    return <div className="flex items-center justify-center h-screen">Loading...</div>;
  }
  
  if (!isAuthenticated) {
    return <Navigate to="/login" />;
  }
  
  if (!user.roles || !user.roles.includes("ROLE_ADMIN")) {
    return <Navigate to="/dashboard" />;
  }
  
  return children;
};

const AppRoutes = () => {
  const { isAuthenticated, logout, user } = useAuth();

  return (
    <BrowserRouter>
      <div className="min-h-screen flex flex-col">
        <Header 
          isLoggedIn={isAuthenticated} 
          onLogout={logout} 
          isAdmin={user?.roles?.includes("ROLE_ADMIN")} 
        />
        <main className="flex-1">
          <Routes>
            <Route path="/" element={isAuthenticated ? <Navigate to="/dashboard" /> : <Landing />} />
            <Route path="/login" element={isAuthenticated ? <Navigate to="/dashboard" /> : <Login />} />
            <Route path="/forgot-password" element={isAuthenticated ? <Navigate to="/dashboard" /> : <ForgotPassword />} />
            <Route path="/reset-password/:token" element={isAuthenticated ? <Navigate to="/dashboard" /> : <ResetPassword />} />
            <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
            <Route path="/upload" element={<ProtectedRoute><UploadComic /></ProtectedRoute>} />
            <Route path="/read/:comicId" element={<ProtectedRoute><ComicReader /></ProtectedRoute>} />
            <Route path="/admin" element={<AdminRoute><AdminDashboard /></AdminRoute>} />
            <Route path="*" element={<NotFound />} />
          </Routes>
        </main>
      </div>
    </BrowserRouter>
  );
};

const App = () => {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider defaultTheme="light">
        <AuthProvider>
          <TagProvider>
            <TooltipProvider>
              <Toaster />
              <Sonner />
              <AppRoutes />
            </TooltipProvider>
          </TagProvider>
        </AuthProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
};

export default App;
