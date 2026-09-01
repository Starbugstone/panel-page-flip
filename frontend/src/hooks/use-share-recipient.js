import { useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import {
  SHARE_CODE_TYPES,
  isValidShareCode,
  isValidShareEmail,
  isValidUsername,
  parseShareCode,
  shareCodeMisuse,
  stripUsernamePrefix,
  usernameHandle,
} from "@/lib/sharing";
import { MODES, TARGETS } from "@/lib/sharing-workflow";

function initialTarget(initialRecipient, initialUserCode) {
  if (initialUserCode) return TARGETS.CODE;
  if (initialRecipient) return TARGETS.EMAIL;
  return TARGETS.USERNAME;
}

function recipientRequest(target, username, userCode) {
  if (target === TARGETS.CODE) {
    return api.post("/api/shares/user-code/resolve", { userCode: parseShareCode(userCode)?.code });
  }

  return api.post("/api/users/resolve-username", { username: stripUsernamePrefix(username) });
}

function recipientIsChosen(mode, target, recipientEmail, username, userCode, confirmed) {
  if (mode === MODES.CODE) return true;
  if (target === TARGETS.EMAIL) return isValidShareEmail(recipientEmail);
  if (!confirmed) return false;

  return target === TARGETS.USERNAME
    ? isValidUsername(username)
    : isValidShareCode(userCode, SHARE_CODE_TYPES.USER);
}

function confirmationIsPending(mode, target, username, userCode, confirmed) {
  if (mode !== MODES.DIRECT || target === TARGETS.EMAIL || confirmed) return false;

  return target === TARGETS.USERNAME
    ? isValidUsername(username)
    : isValidShareCode(userCode, SHARE_CODE_TYPES.USER);
}

function recipientLabel(target, recipientEmail, resolved, username, userCode) {
  if (target === TARGETS.EMAIL) return recipientEmail.trim() || "the recipient";

  return resolved?.label
    || usernameHandle(stripUsernamePrefix(username))
    || userCode
    || "the recipient";
}

function recipientPayload(target, username, userCode, recipientEmail) {
  if (target === TARGETS.USERNAME) return { username: stripUsernamePrefix(username) };
  if (target === TARGETS.CODE) return { userCode: parseShareCode(userCode)?.code };
  return { email: recipientEmail.trim() };
}

/**
 * Who a direct share is for, and whether the sender has actually seen whose
 * library it goes to.
 *
 * An address is its own confirmation: the sender typed the thing the comic is
 * going to, and there is no second identity behind it to check against. A
 * username or a `U-` code is not — both name an account the sender cannot see,
 * and a code names one they cannot even read, so a typo reaches a real stranger
 * rather than failing. `resolved` is cleared the moment either field changes,
 * which is what makes the name on screen the identity being shared with.
 */
export function useShareRecipient({ initialRecipient, initialUsername, initialUserCode, initialResolved, mode, onError }) {
  const [target, setTarget] = useState(() => initialTarget(initialRecipient, initialUserCode));
  const [username, setUsername] = useState(initialUsername);
  const [recipientEmail, setRecipientEmail] = useState(initialRecipient);
  const [userCode, setUserCode] = useState(initialUserCode);
  const [resolved, setResolved] = useState(initialResolved);
  const [isResolving, setIsResolving] = useState(false);

  /** Check the identifier names somebody, before anything is offered to them. */
  const resolveRecipient = async () => {
    setIsResolving(true);
    onError(null);
    setResolved(null);

    try {
      const data = await recipientRequest(target, username, userCode);
      setResolved(data.recipient);
    } catch (err) {
      logger.error("Resolving a recipient failed:", err);
      onError(err.message || "That recipient could not be found.");
    } finally {
      setIsResolving(false);
    }
  };

  const recipientConfirmed = resolved !== null;
  const recipientChosen = recipientIsChosen(
    mode, target, recipientEmail, username, userCode, recipientConfirmed
  );

  // Said out loud, because an inert Send button with no reason beside it reads
  // as a broken dialog rather than as a step not yet taken.
  const confirmationPending = confirmationIsPending(
    mode, target, username, userCode, recipientConfirmed
  );

  return {
    target, setTarget, username, setUsername, recipientEmail, setRecipientEmail,
    userCode, setUserCode, resolved, setResolved, isResolving, resolveRecipient,
    recipientChosen, confirmationPending,
    // A real code of the wrong kind, pasted where a recipient goes. Worth
    // saying out loud rather than answering with "not found": the code is
    // genuine and its holder simply has it in the wrong box.
    targetMisuse: target === TARGETS.CODE ? shareCodeMisuse(userCode, SHARE_CODE_TYPES.USER) : null,
    /** The recipient as the sender knows them. */
    label: recipientLabel(target, recipientEmail, resolved, username, userCode),
    /** Exactly one of the three ways to name a recipient goes on the wire. */
    payload: () => recipientPayload(target, username, userCode, recipientEmail),
  };
}
