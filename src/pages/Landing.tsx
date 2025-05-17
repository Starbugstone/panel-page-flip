
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { BookOpen } from "lucide-react";

export default function Landing() {
  return (
    <div className="min-h-[calc(100vh-4rem)] flex flex-col">
      <section className="flex-1 flex flex-col items-center justify-center text-center px-4 py-12">
        <div className="max-w-5xl mx-auto">
          <div className="mb-8 flex justify-center">
            <BookOpen className="h-16 w-16 text-comic-purple" />
          </div>
          <h1 className="font-comic text-4xl md:text-6xl mb-6 tracking-tight">
            Your Personal Comic Book Reader
          </h1>
          <p className="text-xl md:text-2xl mb-8 text-muted-foreground max-w-3xl mx-auto">
            Enjoy your favorite comics anytime, anywhere. Pick up right where you left off.
          </p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center">
            <Link to="/login">
              <Button size="lg" className="bg-comic-purple hover:bg-comic-purple-dark">
                Log In to Read
              </Button>
            </Link>
            <Link to="/login?signup=true">
              <Button size="lg" variant="outline">
                Create an Account
              </Button>
            </Link>
          </div>
        </div>
      </section>

      <section className="bg-muted py-12 px-4">
        <div className="max-w-5xl mx-auto">
          <h2 className="font-comic text-3xl mb-8 text-center">Features</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div className="bg-card p-6 rounded-lg shadow-sm">
              <h3 className="font-bold text-xl mb-3">Easy Reading</h3>
              <p>Distraction-free comic reading experience optimized for all devices</p>
            </div>
            <div className="bg-card p-6 rounded-lg shadow-sm">
              <h3 className="font-bold text-xl mb-3">Track Progress</h3>
              <p>Automatically saves your reading position so you can pick up where you left off</p>
            </div>
            <div className="bg-card p-6 rounded-lg shadow-sm">
              <h3 className="font-bold text-xl mb-3">CBZ Support</h3>
              <p>Read your comics in the popular CBZ format with our specialized reader</p>
            </div>
          </div>
        </div>
      </section>

      <footer className="bg-background py-6 px-4 border-t">
        <div className="max-w-5xl mx-auto text-center text-sm text-muted-foreground">
          <p>© {new Date().getFullYear()} Comic Reader App. All rights reserved.</p>
        </div>
      </footer>
    </div>
  );
}
