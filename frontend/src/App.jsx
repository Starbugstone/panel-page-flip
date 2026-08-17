import { lazy, Suspense } from "react";
import { Toaster } from "@/components/ui/toaster.jsx";
import { Toaster as Sonner } from "@/components/ui/sonner.jsx";
import { TooltipProvider } from "@/components/ui/tooltip.jsx";
import SessionMonitor from "@/components/SessionMonitor.jsx";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { ThemeProvider } from "@/components/ThemeProvider.jsx";
import { Header } from "@/components/Header.jsx";
import { CookieNotice } from "@/components/CookieNotice.jsx";
import { Footer } from "@/components/Footer.jsx";
import { AuthProvider, useAuth } from "./hooks/use-auth.jsx";
import { TagProvider } from "./hooks/use-tags.jsx";
import { ComicLibraryProvider } from "./hooks/use-comic-library.jsx";
import { SharingProvider } from "./hooks/use-sharing.jsx";

const Landing = lazy(() => import("./pages/Landing.jsx"));
const Login = lazy(() => import("./pages/Login.jsx"));
const Dashboard = lazy(() => import("./pages/Dashboard.jsx"));
const UploadComic = lazy(() => import("./pages/UploadComic.jsx"));
const BulkUploadComic = lazy(() => import("./pages/BulkUploadComic.jsx"));
const ComicReader = lazy(() => import("./pages/ComicReader.jsx"));
const AdminDashboard = lazy(() => import("./pages/AdminDashboard.jsx"));
const AdminUserDetails = lazy(() => import("./pages/AdminUserDetails.jsx"));
const ForgotPassword = lazy(() => import("./pages/ForgotPassword.jsx"));
const ResetPassword = lazy(() => import("./pages/ResetPassword.jsx"));
const NotFound = lazy(() => import("./pages/NotFound.jsx"));
const ShareInvitation = lazy(() => import("./pages/ShareInvitation.jsx"));
const Sharing = lazy(() => import("./pages/Sharing.jsx"));
const EmailVerification = lazy(() => import("./pages/EmailVerification.jsx"));
const DropboxSyncPage = lazy(() => import("./pages/DropboxSyncPage.jsx"));
const UserSettings = lazy(() => import("./pages/UserSettings.jsx"));
const PrivacyPolicy = lazy(() => import("./pages/LegalPages.jsx").then((module) => ({ default: module.PrivacyPolicy })));
const TermsOfService = lazy(() => import("./pages/LegalPages.jsx").then((module) => ({ default: module.TermsOfService })));
const CookieNoticePage = lazy(() => import("./pages/LegalPages.jsx").then((module) => ({ default: module.CookieNoticePage })));
const ReportContent = lazy(() => import("./pages/ReportContent.jsx"));

const queryClient = new QueryClient();
const PageLoading = () => <div className="flex h-screen items-center justify-center">Loading...</div>;

// Protected route component
const ProtectedRoute = ({ children }) => {
  const { isAuthenticated, loading } = useAuth();
  
  if (loading) {
    return <PageLoading />;
  }
  
  if (!isAuthenticated) {
    return <Navigate to="/login" />;
  }
  
  return children;
};

// Admin route component
const AdminRoute = ({ children }) => {
  const { isAuthenticated, loading, isAdmin } = useAuth();
  
  if (loading) {
    return <PageLoading />;
  }
  
  if (!isAuthenticated) {
    return <Navigate to="/login" />;
  }
  
  if (!isAdmin) {
    return <Navigate to="/dashboard" />;
  }
  
  return children;
};

const AppRoutes = () => {
  const { isAuthenticated, logout, isAdmin } = useAuth();

  return (
    <BrowserRouter>
      <div className="min-h-screen flex flex-col">
        <Header 
          isLoggedIn={isAuthenticated} 
          onLogout={logout} 
          isAdmin={isAdmin} 
        />
        <main className="flex-1">
          <Suspense fallback={<PageLoading />}>
            <Routes>
            <Route path="/" element={isAuthenticated ? <Navigate to="/dashboard" /> : <Landing />} />
            <Route path="/login" element={isAuthenticated ? <Navigate to="/dashboard" /> : <Login />} />
            <Route path="/forgot-password" element={isAuthenticated ? <Navigate to="/dashboard" /> : <ForgotPassword />} />
            <Route path="/reset-password/:token" element={isAuthenticated ? <Navigate to="/dashboard" /> : <ResetPassword />} />
            <Route path="/email-verification" element={<EmailVerification />} />
            <Route path="/privacy" element={<PrivacyPolicy />} />
            <Route path="/terms" element={<TermsOfService />} />
            <Route path="/cookies" element={<CookieNoticePage />} />
            <Route path="/report-content" element={<ReportContent />} />
            <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
            <Route path="/upload" element={<ProtectedRoute><UploadComic /></ProtectedRoute>} />
            <Route path="/upload/bulk" element={<ProtectedRoute><BulkUploadComic /></ProtectedRoute>} />
            <Route path="/read/:comicId" element={<ProtectedRoute><ComicReader /></ProtectedRoute>} />
            <Route path="/admin" element={<AdminRoute><AdminDashboard /></AdminRoute>} />
            <Route path="/admin/users/:userId" element={<AdminRoute><AdminUserDetails /></AdminRoute>} />
            <Route path="/sharing" element={<ProtectedRoute><Sharing /></ProtectedRoute>} />
            {/* Not protected: an invited person may not have an account yet, and
                the preview is what tells them what they are being offered. */}
            <Route path="/share/invitation/:token" element={<ShareInvitation />} />
            <Route path="/dropbox-sync" element={<ProtectedRoute><DropboxSyncPage /></ProtectedRoute>} />
            <Route path="/settings" element={<ProtectedRoute><UserSettings /></ProtectedRoute>} />
            <Route path="*" element={<NotFound />} />
            </Routes>
          </Suspense>
        </main>
        <Footer />
        <CookieNotice />
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
            {/* Outside the router so the library survives navigating into a
                comic and back, instead of being refetched from empty. */}
            <ComicLibraryProvider>
              {/* One place holding the pending-invitation count, so the header
                  badge and the dashboard alert cannot disagree about it. */}
              <SharingProvider>
                <TooltipProvider>
                  <Toaster />
                  <Sonner />
                  <SessionMonitor />
                  <AppRoutes />
                </TooltipProvider>
              </SharingProvider>
            </ComicLibraryProvider>
          </TagProvider>
        </AuthProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
};

export default App;
