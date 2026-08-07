import { CorruptPayload, Fragment, WrongPassword, open, parseFragment } from "../crypto";
import { ApiFailure, reportNote, takeNote } from "../api";
import { copyButton, el, mount, notice } from "../dom";
import { t } from "../i18n";

/**
 * Read flow.
 *
 * Nothing in this file runs on page load beyond rendering a button. The note is
 * destroyed only after a deliberate click, because this page is fetched by every
 * link unfurler, mail scanner and prefetching browser that ever sees the URL, and
 * because a user who arrives by accident, hits back, or restores a tab must not
 * lose the note.
 *
 * When the fragment says a password is required we collect it *before* the
 * destroying request, so that a reader without the password can still walk away
 * with the note intact.
 */
export function renderRead(): void {
  const fragment = parseFragment(window.location.hash);

  if (fragment === null) {
    mount(el("h1", { class: "title" }, [t("read.gone.title")]), notice("danger", t("read.badlink")));
    return;
  }

  renderGate(fragment);
}

function noteId(): string {
  return window.location.pathname.replace(/^\/n\//, "");
}

function renderGate(fragment: Fragment): void {
  const status = el("p", { class: "status", role: "status" });
  const reveal = el("button", { type: "button", class: "btn-danger" }, [t("read.reveal")]);

  const password = el("input", {
    type: "password",
    class: "input",
    placeholder: t("read.password.placeholder"),
    autocomplete: "off",
  });
  password.addEventListener("keydown", (event) => {
    if ((event as KeyboardEvent).key === "Enter") reveal.click();
  });

  reveal.addEventListener("click", async () => {
    if (fragment.passwordRequired && password.value.length === 0) {
      status.textContent = t("read.password.prompt");
      password.focus();
      return;
    }

    reveal.disabled = true;
    password.disabled = true;
    status.textContent = t("read.working");

    let payload: string;
    try {
      // The point of no return.
      payload = await takeNote(noteId());
    } catch (error) {
      if (error instanceof ApiFailure && error.kind === "rate_limited") {
        status.textContent = t("common.ratelimited");
        reveal.disabled = false;
        password.disabled = false;
        return;
      }
      renderGone();
      return;
    }

    status.textContent = t("read.decrypting");
    await decryptAndShow(payload, fragment, password.value);
  });

  const parts: Node[] = [
    el("h1", { class: "title" }, [t("read.title")]),
    notice("danger", t("read.warning")),
  ];
  if (fragment.passwordRequired) {
    parts.push(el("p", { class: "tagline" }, [t("read.password.prompt")]), password);
  }
  parts.push(reveal, status, reportLink());

  mount(...parts);
  if (fragment.passwordRequired) password.focus();
}

/**
 * The note is already destroyed by the time this runs, so a wrong password must
 * be retryable here and now. The ciphertext stays in this closure; there is no
 * second chance once the page is closed, which the copy states plainly.
 */
async function decryptAndShow(payload: string, fragment: Fragment, password: string): Promise<void> {
  try {
    const plaintext = await open(payload, fragment, password || undefined);
    renderRevealed(plaintext);
  } catch (error) {
    if (error instanceof WrongPassword) {
      renderRetry(payload, fragment, t("read.wrongpassword"));
      return;
    }
    if (error instanceof CorruptPayload) {
      mount(el("h1", { class: "title" }, [t("read.gone.title")]), notice("danger", t("read.corrupt")));
      return;
    }
    mount(el("h1", { class: "title" }, [t("read.gone.title")]), notice("danger", t("common.error")));
  }
}

function renderRetry(payload: string, fragment: Fragment, message: string): void {
  const password = el("input", {
    type: "password",
    class: "input",
    placeholder: t("read.password.placeholder"),
    autocomplete: "off",
  });
  const again = el("button", { type: "button", class: "btn-primary" }, [t("common.retry")]);

  again.addEventListener("click", async () => {
    if (password.value.length === 0) return;
    again.disabled = true;
    await decryptAndShow(payload, fragment, password.value);
    again.disabled = false;
  });
  password.addEventListener("keydown", (event) => {
    if ((event as KeyboardEvent).key === "Enter") again.click();
  });

  mount(
    el("h1", { class: "title" }, [t("read.title")]),
    notice("danger", message),
    password,
    again,
  );
  password.focus();
}

function renderRevealed(plaintext: string): void {
  // textContent, never innerHTML: this is attacker-supplied text.
  const body = el("pre", { class: "note-body", tabindex: "0" }, [plaintext]);

  mount(
    notice("danger", t("read.revealed.warning")),
    el("div", { class: "row row-end" }, [copyButton(() => plaintext)]),
    body,
    reportLink(),
  );

  // Discourage a reload from looking like a way back. The fragment is the only
  // copy of the key, and the note is gone regardless.
  window.addEventListener("beforeunload", (event) => {
    event.preventDefault();
    event.returnValue = "";
  });
}

function renderGone(): void {
  mount(
    el("h1", { class: "title" }, [t("read.gone.title")]),
    el("p", { class: "tagline" }, [t("read.gone.body")]),
  );
}

function reportLink(): HTMLElement {
  const link = el("button", { type: "button", class: "btn-link" }, [t("read.report")]);
  link.addEventListener("click", async () => {
    link.disabled = true;
    try {
      await reportNote(noteId(), "abuse");
    } catch {
      /* Reporting is best effort; never block the reader on it. */
    }
    link.textContent = t("read.reported");
  });

  return el("p", { class: "footer" }, [link]);
}
