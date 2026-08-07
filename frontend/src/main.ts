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

function isReadPage(): boolean {
  return window.location.pathname.startsWith("/n/");
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot);
} else {
  boot();
}
