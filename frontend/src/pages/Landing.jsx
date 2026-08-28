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
              Your collection, made readable
            </div>
            <h1 className="max-w-3xl text-4xl font-black tracking-tight sm:text-6xl lg:text-7xl">
              Your comics,
              <span className="block text-comic-purple-dark dark:text-comic-purple-light">
                ready when you are.
              </span>
            </h1>
            <p className="mt-6 max-w-2xl text-lg leading-8 text-muted-foreground sm:text-xl">
              Turn scattered comic files into a personal library you can organize,
              enjoy on any screen, and share with the people who will love them too.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <Button asChild size="lg" className="bg-comic-purple-dark text-white hover:bg-comic-purple-dark/90">
                <Link to="/login?signup=true">
                  Start your library
                  <ArrowRight />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <Link to="/login">Log in</Link>
              </Button>
            </div>
            <p className="mt-6 text-sm text-muted-foreground">
              CBZ, plus server-enabled CBR, CB7, CBT, and PDF support.
            </p>
          </div>

          <div aria-hidden="true" className="relative mx-auto w-full max-w-lg">
            <div className="absolute -inset-5 -z-10 rotate-3 rounded-[2rem] bg-comic-purple-soft/70 blur-sm dark:bg-comic-purple-dark/20" />
            <div className="overflow-hidden rounded-2xl border bg-card shadow-2xl">
              <div className="flex items-center justify-between border-b px-5 py-3">
                <div className="flex items-center gap-2 text-sm font-semibold">
                  <BookOpen className="h-4 w-4 text-comic-purple-dark" />
                  Now reading
                </div>
                <span className="rounded-full bg-comic-purple-soft px-2.5 py-1 text-xs font-semibold text-comic-purple-dark">
                  Page 18 of 24
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
                <span className="text-xs font-medium text-muted-foreground">75%</span>
              </div>
            </div>
            <div className="absolute -bottom-6 -left-3 flex items-center gap-2 rounded-xl border bg-background px-3 py-2 text-sm font-medium shadow-lg sm:-left-8">
              <Gauge className="h-4 w-4 text-comic-purple-dark" />
              Right-sized and cached
            </div>
          </div>
        </div>
      </section>

      <section className="px-4 py-20 sm:py-28">
        <div className="mx-auto max-w-6xl">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-bold uppercase tracking-[0.2em] text-comic-purple-dark dark:text-comic-purple-light">
              More than a file viewer
            </p>
            <h2 className="mt-3 text-3xl font-black tracking-tight sm:text-5xl">
              Make a collection feel collected.
            </h2>
            <p className="mt-5 text-lg text-muted-foreground">
              Panel Page Flip adds the structure, continuity, and connection that a
              folder full of archives cannot.
            </p>
          </div>

          <div className="mt-14 grid gap-6 md:grid-cols-2">
            <article className="rounded-2xl border bg-card p-7 shadow-sm sm:p-9">
              <div className="mb-6 inline-flex rounded-xl bg-comic-purple-soft p-3 text-comic-purple-dark">
                <FolderTree className="h-6 w-6" />
              </div>
              <h3 className="text-2xl font-bold">A library that feels like yours</h3>
              <p className="mt-3 leading-7 text-muted-foreground">
                Build nested folders for series, publishers, reading lists, or
                whatever makes sense to you. Add tags, search the collection, and
                move comics in bulk without touching the original files.
              </p>
              <ul className="mt-6 space-y-3 text-sm font-medium">
                <li className="flex items-center gap-3">
                  <Check className="h-4 w-4 text-comic-purple-dark" />
                  Personal folders and tags
                </li>
                <li className="flex items-center gap-3">
                  <Check className="h-4 w-4 text-comic-purple-dark" />
                  Progress saved automatically
                </li>
                <li className="flex items-center gap-3">
                  <Check className="h-4 w-4 text-comic-purple-dark" />
                  Pick up on another device
                </li>
              </ul>
            </article>

            <article className="rounded-2xl border bg-card p-7 shadow-sm sm:p-9">
              <div className="mb-6 inline-flex rounded-xl bg-comic-purple-soft p-3 text-comic-purple-dark">
                <Share2 className="h-6 w-6" />
              </div>
              <h3 className="text-2xl font-bold">Share the good stuff</h3>
              <p className="mt-3 leading-7 text-muted-foreground">
                Invite a friend directly or send a private sharing code. They get
                their own place in the book and can organize it in their library,
                while you stay in control of access.
              </p>
              <ul className="mt-6 space-y-3 text-sm font-medium">
                <li className="flex items-center gap-3">
                  <Check className="h-4 w-4 text-comic-purple-dark" />
                  Share without duplicating the comic
                </li>
                <li className="flex items-center gap-3">
                  <Check className="h-4 w-4 text-comic-purple-dark" />
                  Each reader keeps their own progress
                </li>
                <li className="flex items-center gap-3">
                  <Check className="h-4 w-4 text-comic-purple-dark" />
                  Revoke access whenever you need to
                </li>
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
              Built for the next page.
            </h2>
            <p className="mt-5 text-lg leading-8 text-muted-foreground">
              The reader does not hand your browser one giant comic file. The server
              opens the source and delivers each page individually, then chooses a
              size that matches the available space and your screen’s sharpness.
            </p>
            <p className="mt-4 text-lg leading-8 text-muted-foreground">
              Each result is right-sized for the screen and cached, so returning
              readers and popular pages skip repeat processing while access remains
              protected on every request.
            </p>
          </div>

          <ol className="relative space-y-3">
            <li className="grid grid-cols-[3rem_1fr] gap-4 rounded-xl border bg-card p-5 shadow-sm">
              <span className="text-sm font-black text-comic-purple-dark">01</span>
              <div>
                <h3 className="font-bold">Open the source</h3>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                  One reader handles archive and PDF formats through the same protected pipeline.
                </p>
              </div>
            </li>
            <li className="grid grid-cols-[3rem_1fr] gap-4 rounded-xl border bg-card p-5 shadow-sm">
              <span className="text-sm font-black text-comic-purple-dark">02</span>
              <div>
                <h3 className="font-bold">Request only nearby pages</h3>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                  The reader prepares what is next and releases distant artwork to keep memory use in check.
                </p>
              </div>
            </li>
            <li className="grid grid-cols-[3rem_1fr] gap-4 rounded-xl border bg-card p-5 shadow-sm">
              <span className="text-sm font-black text-comic-purple-dark">03</span>
              <div>
                <h3 className="font-bold">Fit, cache, and keep reading</h3>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                  Phones avoid oversized scans; larger screens and zoom get more detail when they need it.
                </p>
              </div>
            </li>
          </ol>
        </div>
      </section>

      <section className="px-4 py-20 sm:py-24">
        <div className="mx-auto max-w-5xl rounded-3xl bg-slate-950 px-6 py-12 text-center text-white shadow-xl sm:px-12 sm:py-16">
          <BookOpen className="mx-auto h-8 w-8 text-comic-purple-light" />
          <h2 className="mt-5 text-3xl font-black tracking-tight sm:text-4xl">
            Give your comics a better home.
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-slate-300">
            Organize the collection, share a favorite, and settle in with a reader
            designed to stay out of the story’s way.
          </p>
          <Button asChild size="lg" className="mt-8 bg-comic-purple text-slate-950 hover:bg-comic-purple-light">
            <Link to="/login?signup=true">
              Start your library
              <ArrowRight />
            </Link>
          </Button>
        </div>
      </section>
    </div>
  );
}
