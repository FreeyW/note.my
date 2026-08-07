import { seal } from "../crypto";
import { ApiFailure, createNote } from "../api";
import { copyButton, el, mount, notice } from "../dom";
import { t } from "../i18n";

/**
 * Server payload cap is 32768 base64url characters. Working backwards:
 * 32768 chars -> 24576 bytes, minus the 2-byte header, 16-byte salt,
 * 12-byte IV and 16-byte GCM tag.
 */
const MAX_PLAINTEXT_BYTES = Math.floor((32768 * 3) / 4) - (2 + 16 + 12 + 16);

const TTLS = ["1h", "1d", "7d", "30d"] as const;

export function renderCreate(): void {
  const textarea = el("textarea", {
    class: "note-input",
    placeholder: t("create.placeholder"),
    rows: "12",
    autofocus: true,
    spellcheck: "false",
    autocomplete: "off",
  });

  const counter = el("span", { class: "counter" });
  const ttl = el("select", { class: "select", "aria-label": t("create.ttl") },
    TTLS.map((v) => el("option", { value: v }, [t(`create.ttl.${v}`)])),
  );
  ttl.value = "7d";

  const passwordInput = el("input", {
    type: "password",
    class: "input hidden",
    placeholder: t("create.password.placeholder"),
    autocomplete: "new-password",
  });
  const passwordWarning = notice("warn", t("create.password.warning"));
  passwordWarning.classList.add("hidden");

  const passwordToggle = el("input", { type: "checkbox", id: "pw-toggle", class: "checkbox" });
  passwordToggle.addEventListener("change", () => {
    passwordInput.classList.toggle("hidden", !passwordToggle.checked);
    passwordWarning.classList.toggle("hidden", !passwordToggle.checked);
    if (passwordToggle.checked) passwordInput.focus();
    else passwordInput.value = "";
  });

  const status = el("p", { class: "status", role: "status" });
  const submit = el("button", { type: "button", class: "btn-primary" }, [t("create.submit")]);

  // Count bytes, not characters: one CJK character is three bytes, so a
  // character counter would let a Chinese note fail validation at a third of
  // the length the UI promised.
  const encoder = new TextEncoder();
  const updateCounter = (): void => {
    const used = encoder.encode(textarea.value).length;
    const left = MAX_PLAINTEXT_BYTES - used;
    counter.textContent = left >= 0 ? t("create.remaining", { n: left }) : t("create.toolong", { n: -left });
    counter.classList.toggle("counter-over", left < 0);
    submit.disabled = left < 0 || textarea.value.length === 0;
  };
  textarea.addEventListener("input", updateCounter);
  updateCounter();

  submit.addEventListener("click", async () => {
    const plaintext = textarea.value;
    if (plaintext.length === 0) {
      status.textContent = t("create.empty");
      return;
    }

    const password = passwordToggle.checked ? passwordInput.value : undefined;
    submit.disabled = true;
    status.textContent = password ? t("create.deriving") : t("create.working");

    try {
      // Yield once so the pending state actually paints before PBKDF2 blocks
      // the main thread for up to a second on a phone.
      await new Promise((r) => window.setTimeout(r, 0));

      const sealed = await seal(plaintext, password);
      const { id } = await createNote(sealed.payload, ttl.value);
      const link = `${window.location.origin}/n/${id}#${sealed.fragment}`;

      // Clear the plaintext from the DOM. This is hygiene, not a guarantee —
      // the string may still exist elsewhere in the engine's heap.
      textarea.value = "";
      renderResult(link, Boolean(password));
    } catch (error) {
      status.textContent = describe(error);
      submit.disabled = false;
    }
  });

  mount(
    el("h1", { class: "title" }, [t("create.title")]),
    el("p", { class: "tagline" }, [t("create.tagline")]),
    textarea,
    el("div", { class: "row" }, [
      el("label", { class: "field" }, [el("span", { class: "label" }, [t("create.ttl")]), ttl]),
      counter,
    ]),
    el("label", { class: "check-row", for: "pw-toggle" }, [
      passwordToggle,
      el("span", {}, [t("create.password.toggle")]),
    ]),
    passwordInput,
    passwordWarning,
    submit,
    status,
  );
}

function renderResult(link: string, hasPassword: boolean): void {
  const field = el("input", { type: "text", class: "input link-out", readonly: true });
  field.value = link;
  field.addEventListener("focus", () => field.select());

  const extras: Node[] = [];
  if (hasPassword) {
    extras.push(notice("warn", t("create.done.password")));
  }

  mount(
    el("h1", { class: "title" }, [t("create.done")]),
    el("div", { class: "row" }, [field, copyButton(() => link)]),
    notice("danger", t("create.done.hint")),
    ...extras,
    el("button", {
      type: "button",
      class: "btn-secondary",
      onclick: () => {
        // Drop the link from the DOM before re-rendering.
        field.value = "";
        renderCreate();
      },
    }, [t("create.another")]),
  );
}

function describe(error: unknown): string {
  if (error instanceof ApiFailure) {
    if (error.kind === "rate_limited") return t("common.ratelimited");
    if (error.kind === "quota") return t("common.quota");
    if (error.kind === "too_large") return t("create.toolong", { n: 0 });
  }
  return t("common.error");
}
