import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
import {
  ArrowRight,
  BookOpen,
  Check,
  FolderTree,
  Gauge,
  Layers3,
  Share2,
  Sparkles,
} from "lucide-react";
import { landingCopy, landingPreviewCopy } from "@/lib/landing-copy.js";

/** The middle of the page: what it does, and who it is for. */
function LandingFeatures() {
  return (
    <>
      <section className="px-4 py-20 sm:py-28">
        <div className="mx-auto max-w-6xl">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-bold uppercase tracking-[0.2em] text-comic-purple-dark dark:text-comic-purple-light">
              {landingCopy.featuresEyebrow}
            </p>
            <h2 className="mt-3 text-3xl font-black tracking-tight sm:text-5xl">
              {landingCopy.featuresHeading}
            </h2>
            <p className="mt-5 text-lg text-muted-foreground">
              {landingCopy.featuresIntro}
            </p>
          </div>

          <div className="mt-14 grid gap-6 md:grid-cols-2">
            <article className="rounded-2xl border bg-card p-7 shadow-sm sm:p-9">
              <div className="mb-6 inline-flex rounded-xl bg-comic-purple-soft p-3 text-comic-purple-dark">
                <FolderTree className="h-6 w-6" />
              </div>
              <h3 className="text-2xl font-bold">{landingCopy.library.heading}</h3>
              <p className="mt-3 leading-7 text-muted-foreground">
                {landingCopy.library.body}
              </p>
              <ul className="mt-6 space-y-3 text-sm font-medium">
                {landingCopy.library.points.map((point) => (
                  <li key={point} className="flex items-center gap-3">
                    <Check className="h-4 w-4 text-comic-purple-dark" />
                    {point}
                  </li>
                ))}
              </ul>
            </article>

            <article className="rounded-2xl border bg-card p-7 shadow-sm sm:p-9">
              <div className="mb-6 inline-flex rounded-xl bg-comic-purple-soft p-3 text-comic-purple-dark">
                <Share2 className="h-6 w-6" />
              </div>
              <h3 className="text-2xl font-bold">{landingCopy.sharing.heading}</h3>
              <p className="mt-3 leading-7 text-muted-foreground">
                {landingCopy.sharing.body}
              </p>
              <ul className="mt-6 space-y-3 text-sm font-medium">
                {landingCopy.sharing.points.map((point) => (
                  <li key={point} className="flex items-center gap-3">
                    <Check className="h-4 w-4 text-comic-purple-dark" />
                    {point}
                  </li>
                ))}
              </ul>
            </article>
          </div>
        </div>
      </section>

      <section className="bg-muted/60 px-4 py-20 sm:py-28">
        <div className="mx-auto grid max-w-6xl items-center gap-12 lg:grid-cols-2">
          <div>
            <div className="mb-6 inline-flex rounded-xl bg-comic-purple-soft p-3 text-comic-purple-dark">
              <Layers3 className="h-6 w-6" />
            </div>
            <h2 className="text-3xl font-black tracking-tight sm:text-5xl">
              {landingCopy.reader.heading}
            </h2>
            {landingCopy.reader.paragraphs.map((paragraph, index) => (
              <p key={paragraph} className={`${index === 0 ? "mt-5" : "mt-4"} text-lg leading-8 text-muted-foreground`}>
                {paragraph}
              </p>
            ))}
          </div>

          <ol className="relative space-y-3">
            {landingCopy.reader.steps.map((step) => (
              <li key={step.heading} className="grid grid-cols-[3rem_1fr] gap-4 rounded-xl border bg-card p-5 shadow-sm">
                <span className="text-sm font-black text-comic-purple-dark">{step.number}</span>
                <div>
                  <h3 className="font-bold">{step.heading}</h3>
                  <p className="mt-1 text-sm leading-6 text-muted-foreground">
                    {step.body}
                  </p>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </section>
    </>
  );
}

export default function Landing() {
  return (
    <div className="overflow-hidden">
      <section className="relative border-b px-4 py-16 sm:py-20 lg:py-28">
        <div
          aria-hidden="true"
          className="absolute inset-x-0 top-0 -z-10 h-[34rem] bg-[radial-gradient(circle_at_75%_20%,rgba(155,135,245,0.22),transparent_38%),radial-gradient(circle_at_15%_40%,rgba(214,188,250,0.25),transparent_32%)]"
        />
        <div className="mx-auto grid max-w-6xl items-center gap-14 lg:grid-cols-[1.05fr_0.95fr]">
          <div>
            <div className="mb-6 inline-flex items-center gap-2 rounded-full border bg-background/80 px-3 py-1.5 text-sm font-medium shadow-sm backdrop-blur">
              <Sparkles className="h-4 w-4 text-comic-purple-dark" />
              {landingCopy.badge}
            </div>
            <h1 className="max-w-3xl text-4xl font-black tracking-tight sm:text-6xl lg:text-7xl">
              {landingCopy.headlineLead}
              <span className="block text-comic-purple-dark dark:text-comic-purple-light">
                {landingCopy.headlineEmphasis}
              </span>
            </h1>
            <p className="mt-6 max-w-2xl text-lg leading-8 text-muted-foreground sm:text-xl">
              {landingCopy.intro}
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <Button asChild size="lg" className="bg-comic-purple-dark text-white hover:bg-comic-purple-dark/90">
                <Link to="/login?signup=true">
                  {landingCopy.startLibrary}
                  <ArrowRight />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <Link to="/login">{landingCopy.logIn}</Link>
              </Button>
            </div>
            <p className="mt-6 text-sm text-muted-foreground">
              {landingCopy.formats}
            </p>
          </div>

          <div aria-hidden="true" className="relative mx-auto w-full max-w-lg">
            <div className="absolute -inset-5 -z-10 rotate-3 rounded-[2rem] bg-comic-purple-soft/70 blur-sm dark:bg-comic-purple-dark/20" />
            <div className="overflow-hidden rounded-2xl border bg-card shadow-2xl">
              <div className="flex items-center justify-between border-b px-5 py-3">
                <div className="flex items-center gap-2 text-sm font-semibold">
                  <BookOpen className="h-4 w-4 text-comic-purple-dark" />
                  {landingPreviewCopy.nowReading}
                </div>
                <span className="rounded-full bg-comic-purple-soft px-2.5 py-1 text-xs font-semibold text-comic-purple-dark">
                  {landingPreviewCopy.pageStatus}
                </span>
              </div>
              <div className="grid aspect-[4/3] grid-cols-[1.15fr_0.85fr] gap-2 bg-slate-950 p-3">
                <div className="grid grid-rows-[1fr_0.65fr] gap-2">
                  <div className="rounded-md bg-gradient-to-br from-violet-300 via-comic-purple to-violet-900" />
                  <div className="grid grid-cols-2 gap-2">
                    <div className="rounded-md bg-gradient-to-tr from-amber-200 to-orange-500" />
                    <div className="rounded-md bg-gradient-to-br from-sky-300 to-indigo-700" />
                  </div>
                </div>
                <div className="grid grid-rows-[0.6fr_1fr] gap-2">
                  <div className="rounded-md bg-gradient-to-bl from-fuchsia-300 to-violet-700" />
                  <div className="rounded-md bg-gradient-to-tr from-slate-200 via-violet-200 to-comic-purple" />
                </div>
              </div>
              <div className="flex items-center gap-3 px-5 py-4">
                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                  <div className="h-full w-3/4 rounded-full bg-comic-purple-dark" />
                </div>
                <span className="text-xs font-medium text-muted-foreground">{landingPreviewCopy.progress}</span>
              </div>
            </div>
            <div className="absolute -bottom-6 -left-3 flex items-center gap-2 rounded-xl border bg-background px-3 py-2 text-sm font-medium shadow-lg sm:-left-8">
              <Gauge className="h-4 w-4 text-comic-purple-dark" />
              {landingPreviewCopy.cachedBadge}
            </div>
          </div>
        </div>
      </section>

      <LandingFeatures />

      <section className="px-4 py-20 sm:py-24">
        <div className="mx-auto max-w-5xl rounded-3xl bg-slate-950 px-6 py-12 text-center text-white shadow-xl sm:px-12 sm:py-16">
          <BookOpen className="mx-auto h-8 w-8 text-comic-purple-light" />
          <h2 className="mt-5 text-3xl font-black tracking-tight sm:text-4xl">
            {landingCopy.close.heading}
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-slate-300">
            {landingCopy.close.body}
          </p>
          <Button asChild size="lg" className="mt-8 bg-comic-purple text-slate-950 hover:bg-comic-purple-light">
            <Link to="/login?signup=true">
              {landingCopy.startLibrary}
              <ArrowRight />
            </Link>
          </Button>
        </div>
      </section>
    </div>
  );
}
