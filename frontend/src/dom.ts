import { t } from "./i18n";

/**
 * Minimal DOM construction.
 *
 * Nothing here ever assigns innerHTML. Decrypted note content is user text
 * from an untrusted sender, and the read page renders it directly — textContent
 * is the only safe way in, and having no innerHTML anywhere in the codebase
 * makes that easy to verify by grep.
 */

type Attrs = Record<string, string | boolean | ((e: Event) => void)>;

export function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Attrs = {},
  children: (Node | string)[] = [],
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);

  for (const [name, value] of Object.entries(attrs)) {
    if (typeof value === "function") {
      node.addEventListener(name.replace(/^on/, "").toLowerCase(), value as EventListener);
    } else if (typeof value === "boolean") {
      if (value) node.setAttribute(name, "");
    } else if (name === "class") {
      node.className = value;
    } else {
      node.setAttribute(name, value);
    }
  }

  for (const child of children) {
    node.append(typeof child === "string" ? document.createTextNode(child) : child);
  }

  return node;
}

export function mount(...nodes: Node[]): void {
  const app = document.getElementById("app");
  if (!app) return;
  app.replaceChildren(...nodes);
}

/**
 * Copy to clipboard with a fallback for insecure contexts and older Safari.
 * Returns false so the caller can tell the user to select manually rather than
 * silently doing nothing.
 */
export async function copy(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    /* fall through */
  }

  try {
    const scratch = el("textarea", { class: "sr-only", readonly: true });
    scratch.value = text;
    document.body.append(scratch);
    scratch.select();
    const done = document.execCommand("copy");
    scratch.remove();
    return done;
  } catch {
    return false;
  }
}

export function copyButton(getText: () => string, extraClass = ""): HTMLButtonElement {
  const button = el("button", {
    type: "button",
    class: `btn-secondary ${extraClass}`.trim(),
    onclick: async () => {
      const done = await copy(getText());
      button.textContent = t(done ? "common.copied" : "common.copyfail");
      window.setTimeout(() => {
        button.textContent = t("common.copy");
      }, 2000);
    },
  }, [t("common.copy")]);

  return button;
}

export function notice(kind: "warn" | "danger" | "info", ...children: (Node | string)[]): HTMLElement {
  return el("p", { class: `notice notice-${kind}`, role: kind === "info" ? "status" : "alert" }, children);
}
