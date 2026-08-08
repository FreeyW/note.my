import { isSupported } from "./crypto";
import { t } from "./i18n";
import { el, mount } from "./dom";
import { renderCreate } from "./ui/create";
import { renderRead } from "./ui/read";

/**
 * Entry point. The page kind is read from the URL rather than a data attribute,
 * so the shells stay pure markup with nothing for the bundle to disagree with.
 */
function boot(): void {
  // The 404 shell has no #app: it is finished markup, not a mount point, and
  // renderCreate() would otherwise build a form that mount() silently drops.
  if (!document.getElementById("app")) return;

  localiseBrand();

  if (!isSupported()) {
    mount(el("p", { class: "notice notice-danger" }, [t("common.unsupported")]));
    return;
  }

  // The reading page is /n/{id} for every language, so it picks a locale from
  // the browser. The homepage was already resolved server-side from its URL.
  if (isReadPage()) {
    renderRead();
  } else {
    renderCreate();
  }
}

/**
 * The brand link is server rendered, and on the reading page the server has no
 * locale to render it in — that page picks a language from the browser. Its
 * tooltip is the one piece of translated copy outside #app, so it is restated
 * here once the catalogue has resolved.
 */
function localiseBrand(): void {
  document.querySelector(".brand a")?.setAttribute("title", t("brand.new"));
}

function isReadPage(): boolean {
  return window.location.pathname.startsWith("/n/");
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot);
} else {
  boot();
}
