export function createContentReportReview(report) {
  return {
    status: report.status === "received" ? "under_review" : report.status,
    // `undefined` leaves the canonical target untouched, `null` unlinks it,
    // and an object selects a replacement.
    pendingTarget: undefined,
    resolutionCode: report.resolutionCode || "",
    resolutionNote: report.resolutionNote || "",
    legalHold: Boolean(report.legalHold),
    action: "none",
    notifyOwner: false,
  };
}

export function contentReportReviewPayload(review) {
  return {
    status: review.status,
    resolutionCode: review.resolutionCode || null,
    resolutionNote: review.resolutionNote || null,
    legalHold: review.legalHold,
    action: review.action,
    notifyOwner: review.notifyOwner,
    ...(review.pendingTarget !== undefined
      ? { targetType: review.pendingTarget?.type ?? null, targetId: review.pendingTarget?.id ?? null }
      : {}),
  };
}

export function hasContentReportTargetSnapshot(snapshot) {
  return Boolean(snapshot?.userId || snapshot?.comicId || snapshot?.shareId);
}

export function contentReportActionSupports(target, requirement) {
  if (!target) return false;
  if (requirement === "comic") return target.type === "comic" || target.type === "share";
  if (requirement === "user") return target.type === "user" || Boolean(target.owner);
  return true;
}

export function contentReportTargetLabel(target, requirement) {
  if (requirement === "user") {
    return target.type === "user"
      ? (target.name || target.email || "selected account")
      : (target.owner?.name || "the selected content owner");
  }

  return target.title || target.name || `${contentReportLabel(target.type)} #${target.id}`;
}

export function contentReportResolutionMessage(resolution) {
  if (resolution?.status === "exact") {
    return "An exact application-issued reference matched privately. Confirm the human-readable target before linking it.";
  }
  if (resolution?.status === "candidates") {
    return "Possible targets were found. A human administrator must choose the correct record.";
  }

  return "No exact target is linked. Search candidates using the submitted information.";
}

export function contentReportLabel(value) {
  return value
    ? value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase())
    : "—";
}
