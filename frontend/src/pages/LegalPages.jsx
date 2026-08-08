import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

const LAST_UPDATED = "5 August 2026";

function useLegalConfig() {
  const [config, setConfig] = useState({
    operator: "Panel Page Flip site operator",
    privacyEmail: null,
  });

  useEffect(() => {
    api.get("/api/legal-config", { notifyUnauthorized: false })
      .then(setConfig)
      .catch((error) => logger.warn("Could not load legal contact details:", error.message));
  }, []);

  return config;
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
        <Link className="underline" to="/">Home</Link>
      </nav>
    </div>
  );
}

export function PrivacyPolicy() {
  const { operator, privacyEmail } = useLegalConfig();

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
        <li>Security and operations data: session identifiers, IP-based rate-limit entries, access logs, and administrator audit records.</li>
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
        We do not use advertising networks or third-party analytics.
      </p>
      <p>
        A provider may process data outside your country. Where GDPR requires it,
        the operator must use an appropriate transfer safeguard and processor agreement.
      </p>

      <h2>Retention</h2>
      <ul>
        <li>Your account, library, and reading activity remain until you delete the account or the operator removes it.</li>
        <li>Unverified accounts are deleted after 30 days.</li>
        <li>Administrator audit records are deleted after 12 months.</li>
        <li>Expired verification, password-reset, and share tokens are removed by scheduled cleanup.</li>
        <li>Infrastructure access logs are retained according to the hosting configuration and should be limited to the shortest operationally necessary period.</li>
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
        Passwords are hashed, sessions use CSRF protection, and stored Dropbox
        credentials are encrypted. No internet service can guarantee absolute security.
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
  const { operator, privacyEmail } = useLegalConfig();

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
        direct through the service. Upload or share content only when you have the
        necessary rights and permissions.
      </p>

      <h2>Acceptable use</h2>
      <p>
        Do not use the service unlawfully, attempt unauthorized access, interfere
        with its operation, distribute malware, or use sharing features for spam or abuse.
      </p>

      <h2>Availability and changes</h2>
      <p>
        This is a self-hosted service provided without a guaranteed availability
        level. Features may change, and maintenance or circumstances outside the
        operator’s control may interrupt access.
      </p>

      <h2>Suspension and termination</h2>
      <p>
        The operator may restrict an account to protect the service, comply with law,
        or respond to a material breach. You may end these terms by deleting your account.
      </p>

      <h2>Liability</h2>
      <p>
        To the extent permitted by applicable law, the service is provided “as is”.
        Nothing in these terms excludes rights or liability that cannot legally be excluded.
      </p>

      <h2>Contact</h2>
      <p>Questions about these terms may be sent to <Contact email={privacyEmail} />.</p>
    </LegalLayout>
  );
}

export function CookieNoticePage() {
  return (
    <LegalLayout title="Cookie Notice">
      <p>
        Panel Page Flip does not use advertising or analytics cookies. It uses only
        storage needed to operate the service and remember your theme preference.
      </p>

      <h2>Storage used</h2>
      <ul>
        <li><strong>Session cookie:</strong> keeps you signed in. It lasts for the configured session lifetime.</li>
        <li><strong>XSRF-TOKEN:</strong> protects authenticated actions against cross-site request forgery.</li>
        <li><strong>comic-reader-theme:</strong> remembers light or dark appearance for up to 365 days.</li>
        <li><strong>Local browser storage:</strong> remembers the theme during migration and whether you dismissed the cookie notice.</li>
      </ul>

      <h2>Your choices</h2>
      <p>
        Session and security storage are necessary for signed-in features. You can
        clear site data in your browser. Doing so signs you out and resets preferences.
        Because no advertising or analytics storage is used, there is no tracking-consent panel.
      </p>
    </LegalLayout>
  );
}
