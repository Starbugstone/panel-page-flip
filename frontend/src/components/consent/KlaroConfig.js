import ConsentManager from "klaro/src/consent-manager.js";
import { t } from "klaro/src/utils/i18n.js";

const translations = {
  en: {
    acceptAll: "Accept all", acceptSelected: "Save choices", save: "Save choices",
    decline: "Reject all", ok: "Accept all", close: "Close",
    consentNotice: {
      title: "Analytics preferences", learnMore: "Customize",
      description: "May we use Google Analytics to measure which pages of this site are used? We send a fixed page category, never your comics, searches or anything you type. Everything works either way. You can change your choice through Analytics preferences. {privacyPolicy}",
    },
    consentModal: { title: "Analytics preferences", description: "Choose whether to allow Google Analytics. You can withdraw your choice at any time." },
    privacyPolicy: { name: "Privacy policy", text: "Read our {privacyPolicy}." },
    purposes: { analytics: "Audience measurement" },
    service: { purpose: "Purpose", purposes: "Purposes" },
    "google-analytics": { description: "Measures fixed page categories with analytics cookies. Your library, comic details and searches are never sent." },
  },
  fr: {
    acceptAll: "Tout accepter", acceptSelected: "Enregistrer mes choix", save: "Enregistrer mes choix",
    decline: "Tout refuser", ok: "Tout accepter", close: "Fermer",
    consentNotice: {
      title: "Préférences de mesure d’audience", learnMore: "Personnaliser",
      description: "Pouvons-nous utiliser Google Analytics pour mesurer la fréquentation du site ? Nous transmettons uniquement une catégorie de page, jamais vos bandes dessinées, recherches ou saisies. Le site fonctionne quel que soit votre choix. Vous pouvez le modifier à tout moment dans les préférences de mesure d’audience. {privacyPolicy}",
    },
    consentModal: { title: "Préférences de mesure d’audience", description: "Choisissez si vous autorisez Google Analytics. Vous pouvez retirer votre accord à tout moment." },
    privacyPolicy: { name: "Politique de confidentialité", text: "Consultez notre {privacyPolicy}." },
    purposes: { analytics: "Mesure d’audience" },
    service: { purpose: "Finalité", purposes: "Finalités" },
    "google-analytics": { description: "Mesure des catégories de pages avec des cookies statistiques. Votre bibliothèque, vos bandes dessinées et vos recherches ne sont jamais transmises." },
  },
};

export function createKlaroConfig(language = navigator.language) {
  const lang = language?.toLowerCase().startsWith("fr") ? "fr" : "en";
  return {
    lang, translations, privacyPolicy: "/privacy", default: false,
    acceptAll: true, hideDeclineAll: false, showNoticeTitle: true,
    groupByPurpose: false, disablePoweredBy: true, htmlTexts: false,
    services: [{ name: "google-analytics", title: "Google Analytics", purposes: ["analytics"] }],
  };
}

export function createKlaroManager(config, decision, onDecision) {
  // Keep the existing versioned, expiring localStorage record as the single
  // answer. Klaro owns the controls; ConsentProvider owns persistence and GA4.
  const store = {
    get: () => decision === "undecided" ? null
      : encodeURIComponent(JSON.stringify({ "google-analytics": decision === "granted" })),
    set: (value) => onDecision(JSON.parse(decodeURIComponent(value))["google-analytics"] ? "granted" : "denied"),
  };
  const manager = new ConsentManager(config, store, {});
  // Reopening keeps refusal on the first layer, even after a previous grant.
  manager.confirmed = false;
  return manager;
}

export function klaroTranslate(config, ...args) {
  return t(config.translations, config.lang, "en", ...args);
}
