import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { isAdvertisingActive } from "@/lib/advertising";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";

const LAST_UPDATED = "22 August 2026";

function useLegalConfig() {
  const [config, setConfig] = useState({
    operator: "Panel Page Flip site operator",
    privacyEmail: null,
    legalEmail: null,
  });

  useEffect(() => {
    api.get("/api/legal-config", { notifyUnauthorized: false })
      .then(setConfig)
      .catch((error) => logger.warn("Could not load legal contact details:", error.message));
  }, []);

  return config;
}

/**
 * Whether this installation actually shows advertising.
 *
 * The same build serves an ad-free self-hosted library and a monetised public
 * one, so these pages describe what is running rather than what the software can
 * do. A privacy policy that lists a processor nobody uses is as wrong as one
 * that omits a processor they do.
 */
function useAdvertisingInUse() {
  const { config, isLoading } = useAdSense();

  // Null, not false, until the answer arrives. These pages carry absolute
  // claims in both directions — "we do not use advertising networks" is a
  // statement of fact on an indexable page — and defaulting to the negative one
  // for the length of a round trip publishes the wrong fact on every load of an
  // installation that does show advertising. Unknown renders neither claim.
  return isLoading ? null : isAdvertisingActive(config);
}

/**
 * Reopening the consent message from inside the policy text.
 *
 * The publisher id has to be passed through because the consent platform is
 * fetched on demand: these pages are ad-free, so nothing Google-owned has been
 * loaded by the time somebody reads them and decides to change their mind.
 */
function useReopenPrivacyChoices() {
  const { config } = useAdSense();

  return () => reopenPrivacyChoices({ client: config.client });
}

function Contact({ email }) {
  if (!email) return <span>the contact address provided by the site operator</span>;
  return <a className="underline" href={`mailto:${email}`}>{email}</a>;
}

function LegalLayout({ title, children }) {
  useEffect(() => {
    const previousTitle = document.title;
    const canonical = document.querySelector('link[rel="canonical"]');
    const openGraphUrl = document.querySelector('meta[property="og:url"]');
    const previousCanonical = canonical?.getAttribute("href");
    const previousOpenGraphUrl = openGraphUrl?.getAttribute("content");
    const configuredOrigin = (import.meta.env.VITE_APP_URL || window.location.origin).replace(/\/$/, "");
    const path = window.location.pathname.replace(/\/+$/, "") || "/";
    const pageUrl = `${configuredOrigin}${path === "/" ? "/" : path}`;

    document.title = `${title} | Panel Page Flip`;
    canonical?.setAttribute("href", pageUrl);
    openGraphUrl?.setAttribute("content", pageUrl);

    return () => {
      document.title = previousTitle;
      if (canonical && previousCanonical) canonical.setAttribute("href", previousCanonical);
      if (openGraphUrl && previousOpenGraphUrl) openGraphUrl.setAttribute("content", previousOpenGraphUrl);
    };
  }, [title]);

  return (
    <div className="container mx-auto max-w-4xl px-4 py-10">
      <article className="prose prose-slate max-w-none dark:prose-invert">
        <h1 className="font-comic">{title}</h1>
        <p className="text-sm text-muted-foreground">Last updated: {LAST_UPDATED}</p>
        {children}
      </article>
      <nav className="mt-10 flex flex-wrap gap-4 border-t pt-6 text-sm">
        <Link className="underline" to="/privacy">Privacy</Link>
        <Link className="underline" to="/terms">Terms</Link>
        <Link className="underline" to="/cookies">Cookies</Link>
        <Link className="underline" to="/report-content">Report illegal content</Link>
        <Link className="underline" to="/">Home</Link>
      </nav>
    </div>
  );
}

export function PrivacyPolicy() {
  const { operator, privacyEmail } = useLegalConfig();

  const advertising = useAdvertisingInUse();
  const reopenChoices = useReopenPrivacyChoices();

  return (
    <LegalLayout title="Privacy Policy">
      <p>
        This policy explains how {operator} (“we”, “us”) processes personal data
        when you use Panel Page Flip.
      </p>

      <h2>Data controller and contact</h2>
      <p>
        The data controller is {operator}. For privacy questions or to exercise
        your rights, contact <Contact email={privacyEmail} />.
      </p>

      <h2>Data we process</h2>
      <ul>
        <li>Account information: email address, display name, password hash, roles, verification status, and account timestamps.</li>
        <li>Library data: uploaded comic files and metadata, tags, and ownership records.</li>
        <li>Reading activity: current page, completion status, and last-read time.</li>
        <li>Sharing data: recipient email addresses, expiring share tokens, and the identity of the person sharing.</li>
        <li>Dropbox data, when connected: encrypted OAuth credentials, import paths, and last-sync time.</li>
        <li>Metadata-provider credentials, when you add your own: an encrypted API token, write-only after saving and removable at any time.</li>
        <li>Security and operations data: session identifiers, IP-based rate-limit entries, access logs, and administrator audit records.</li>
        <li>Content reports: reporter contact details, the supplied allegation and references, review notes, linked internal records, and action history.</li>
      </ul>

      <h2>Why we process it</h2>
      <ul>
        <li><strong>Contract:</strong> to create your account and provide the library, reader, sharing, and optional Dropbox features you request.</li>
        <li><strong>Legitimate interests:</strong> to secure, troubleshoot, and administer the service, prevent abuse, and keep proportionate audit records.</li>
        <li><strong>Legal obligations:</strong> where records must be retained or disclosed under applicable law.</li>
      </ul>

      <h2>Who receives data</h2>
      <p>
        We use the hosting and database infrastructure selected by the operator.
        Email delivery providers receive recipient addresses and message content.
        If you connect Dropbox, Dropbox receives API requests needed to list and
        import the files you select. Dropbox is optional and can be disconnected.
        {advertising === null ? null : advertising
          ? " Google serves advertising on a small number of pages, described below. We do not use third-party analytics."
          : " We do not use advertising networks or third-party analytics."}
      </p>
      <p>
        <strong>Metadata providers.</strong> When you explicitly search for a comic’s
        details, the provider you choose receives the identifying fields of that
        search and nothing else: Metron receives the series name, the issue number and
        the year; Comic Vine receives the series name and issue number as a single
        search phrase. In particular the provider does not receive your identity
        or email address, your file or Dropbox paths, your reading history, your tags,
        or the comic file itself. A search only happens when you ask for one; uploading
        a comic never contacts a provider. Results are cached on this server so the same
        search is not repeated, and an administrator can turn provider access off for the
        whole installation or for one account.
      </p>
      <p>
        If you add your own provider token in your settings, searches use it instead of
        the server’s. It is encrypted at rest, never shown again once saved, removable
        from the same page, and deleted with your account. It is deliberately excluded
        from the data export, because an export is a file that leaves the server.
      </p>
      <p>
        A provider may process data outside your country. Where GDPR requires it,
        the operator must use an appropriate transfer safeguard and processor agreement.
      </p>

      {advertising && (
        <>
          <h2>Advertising</h2>
          <p>
            This installation shows Google AdSense advertising to help finance its
            hosting and running costs. Advertising appears only on pages this
            application owns — the landing page, the login page, the single-comic
            upload form and the bulk-upload information page. It is never shown on
            the reader, on your library, beside a cover or a page image, or beside
            anything read out of a comic you uploaded.
          </p>
          <p>
            Google and its approved advertising partners may process online
            identifiers, device and browser information, your consent signals,
            information derived from your IP address, and how you interacted with
            an advertisement. They act as independent controllers for that
            processing under their own terms. We do not send them your email
            address, your library, your reading history, your tags, your filenames
            or any part of a comic file.
          </p>
          <p>
            Where the EEA, UK or Swiss rules apply, a Google-certified consent
            management platform asks for your choices before any non-essential
            advertising storage or personalised advertising request. You can
            accept all, reject all, or choose individually; rejecting is as easy
            as accepting; and you can change or withdraw your choices at any time
            using{" "}
            <button type="button" className="underline" onClick={reopenChoices}>
              privacy choices
            </button>
            , which also appears in the footer of every page.
          </p>
          <p>
            Refusing advertising consent does not prevent you from registering,
            signing in, uploading, organising or reading your comics. Where the
            bulk uploader offers a rewarded advertisement, it is optional: single
            comic upload is always available without watching one, and if no
            rewarded advertisement can be served, bulk upload opens normally.
            Showing an advertisement is not an endorsement of what it advertises.
          </p>
        </>
      )}

      <h2>Retention</h2>
      <ul>
        <li>Your account, library, and reading activity remain until you delete the account or the operator removes it.</li>
        <li>Unverified accounts are deleted after 30 days.</li>
        <li>Administrator audit records are deleted after 12 months.</li>
        <li>Expired verification, password-reset, and share tokens are removed by scheduled cleanup.</li>
        <li>Infrastructure access logs are retained according to the hosting configuration and should be limited to the shortest operationally necessary period.</li>
        <li>Closed or rejected content reports are normally deleted after two years. Open cases and records subject to a legal hold or ongoing legal obligation are retained while needed.</li>
      </ul>

      <h2>Your rights</h2>
      <p>
        Subject to applicable law, you may access, correct, export, erase, restrict,
        or object to processing of your personal data. Account settings provide a
        machine-readable export and account deletion. You may also contact us, and
        you may complain to your local data-protection authority.
      </p>

      <h2>Security</h2>
      <p>
        Passwords are hashed, sessions use CSRF protection, and stored Dropbox and
        metadata-provider credentials are encrypted. No internet service can guarantee
        absolute security.
      </p>

      <h2>Other people’s data</h2>
      <p>
        Only share a recipient’s email address when you are entitled to do so. Share
        invitations explain who initiated the invitation and why the address was used.
      </p>

      <h2>Changes</h2>
      <p>Material changes will be published here with a revised update date.</p>
    </LegalLayout>
  );
}

export function TermsOfService() {
  const { operator, privacyEmail, legalEmail } = useLegalConfig();
  const advertising = useAdvertisingInUse();

  return (
    <LegalLayout title="Terms of Service">
      <p>
        These terms govern your use of Panel Page Flip, operated by {operator}.
        By creating an account, you agree to these terms and the Privacy Policy.
      </p>

      <h2>Your account</h2>
      <p>
        Provide accurate account information, protect your credentials, and notify
        the operator if you believe your account has been compromised. You are
        responsible for activity performed through your account.
      </p>

      <h2>Your content</h2>
      <p>
        You retain rights in content you upload. You grant the operator only the
        permission needed to store, process, display, and share that content as you
        direct through the service. Upload content only when you are authorized to
        host it, and use sharing only when you have the necessary right or authorization
        to distribute it to the recipient. Owning or lawfully possessing a copy does
        not necessarily grant a right to distribute it.
      </p>

      <h2>Acceptable use</h2>
      <p>
        Do not use the service unlawfully, attempt unauthorized access, interfere
        with its operation, distribute malware, or use sharing features for spam or abuse.
      </p>

      {advertising && (
        <>
          <h2>Advertising</h2>
          <p>
            Some pages of this service that contain only the operator’s own
            content may display third-party advertising. Advertising is not an
            endorsement of the advertiser or of what is advertised, and the
            operator does not control which advertisements are shown.
          </p>
          <p>
            Do not click, automate, script, or otherwise interact with
            advertisements other than through genuine interest, and do not
            encourage anyone else to. Advertising fraud breaches the advertising
            provider’s terms and may result in suspension. Nobody should click an
            advertisement in order to support this project.
          </p>
        </>
      )}

      <h2>Availability and changes</h2>
      <p>
        This is a self-hosted service provided without a guaranteed availability
        level. Features may change, and maintenance or circumstances outside the
        operator’s control may interrupt access.
      </p>

      <h2>Suspension and termination</h2>
      <p>
        The operator may restrict sharing, revoke recipient access, quarantine or remove
        content, or suspend an account to protect the service, comply with law, or respond
        to a material breach. Repeated substantiated unlawful distribution may result in
        account-level sharing restrictions or suspension. These are proportionate
        administrative decisions, not an automatic strikes system. You may end these
        terms by deleting your account.
      </p>

      <h2>Liability</h2>
      <p>
        To the extent permitted by applicable law, the service is provided “as is”.
        Nothing in these terms excludes rights or liability that cannot legally be excluded.
      </p>

      <h2>Contact</h2>
      <p>
        Questions about these terms may be sent to <Contact email={privacyEmail} />.
        Notices about specific allegedly illegal material may be submitted through the{" "}
        <Link className="underline" to="/report-content">Report illegal content</Link> form
        {legalEmail ? <> or sent to <Contact email={legalEmail} /></> : null}.
      </p>
    </LegalLayout>
  );
}

export function CookieNoticePage() {
  const advertising = useAdvertisingInUse();
  const reopenChoices = useReopenPrivacyChoices();

  return (
    <LegalLayout title="Cookie Notice">
      <p>
        {advertising === null ? null : advertising
          ? "Panel Page Flip uses storage needed to operate the service and remember your theme preference. On a small number of pages it also shows Google advertising, which uses storage of its own — you decide whether to allow that."
          : "Panel Page Flip does not use advertising or analytics cookies. It uses only storage needed to operate the service and remember your theme preference."}
      </p>

      <h2>Storage used</h2>
      <ul>
        <li><strong>Session cookie:</strong> keeps you signed in. It lasts for the configured session lifetime.</li>
        <li><strong>XSRF-TOKEN:</strong> protects authenticated actions against cross-site request forgery.</li>
        <li><strong>comic-reader-theme:</strong> remembers light or dark appearance for up to 365 days.</li>
        <li><strong>Local browser storage:</strong> remembers the theme during migration and whether you dismissed the cookie notice.</li>
        {advertising && (
          <li>
            <strong>Consent choices:</strong> the Google-certified consent platform stores the
            choices you made, so it does not ask again on every visit.
          </li>
        )}
        {advertising && (
          <li>
            <strong>Google advertising storage:</strong> cookies and local storage set by Google
            and its advertising partners on pages that carry advertising, used to serve, limit
            and measure advertisements and to detect invalid traffic. Exactly what is set depends
            on the choices you make and on the advertising configuration in use, so this notice
            does not promise that refusing personalisation leaves no storage at all — the
            consent panel is where the current answer lives.
          </li>
        )}
      </ul>

      <h2>Your choices</h2>
      <p>
        Session and security storage are necessary for signed-in features. You can
        clear site data in your browser. Doing so signs you out and resets preferences.
      </p>
      {advertising === null ? null : advertising ? (
        <>
          <p>
            Advertising storage is not necessary, and you choose whether to allow it. Where the
            EEA, UK or Swiss rules apply, the consent panel appears before any non-essential
            advertising storage is used; rejecting is as easy as accepting. Reopen it at any time
            through{" "}
            <button type="button" className="underline" onClick={reopenChoices}>
              privacy choices
            </button>
            , which is also in the footer of every page.
          </p>
          <p>
            Refusing does not sign you out, hide your library, or take away any feature. Comic
            pages, covers, library screens and the reader carry no advertising at all.
          </p>
        </>
      ) : (
        <p>
          Because no advertising or analytics storage is used, there is no tracking-consent panel.
        </p>
      )}
    </LegalLayout>
  );
}
