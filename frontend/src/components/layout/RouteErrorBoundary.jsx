import { Component } from "react";
import { Link } from "react-router-dom";
import { AlertCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { PageLayout } from "./PageLayout";

export class RouteErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { failed: false };
  }

  static getDerivedStateFromError() {
    return { failed: true };
  }

  render() {
    if (!this.state.failed) return this.props.children;

    return (
      <PageLayout width="reading">
        <div role="alert" className="rounded-xl border bg-card p-6 text-center sm:p-10">
          <AlertCircle aria-hidden="true" className="mx-auto mb-4 h-8 w-8 text-primary" />
          <h1 className="page-title">This page could not be displayed</h1>
          <p className="mx-auto mt-3 max-w-lg text-muted-foreground">Try opening it again. If the problem continues, reload the page or return home.</p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Button onClick={() => this.setState({ failed: false })}>Try again</Button>
            <Button asChild variant="outline"><Link to="/">Back to home</Link></Button>
          </div>
        </div>
      </PageLayout>
    );
  }
}
