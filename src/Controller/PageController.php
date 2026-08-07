<?php

declare(strict_types=1);

namespace NoteMy\Controller;

use NoteMy\Http\Request;
use NoteMy\Http\Response;
use NoteMy\I18n;
use RuntimeException;

/**
 * Static pages only.
 *
 * This class has no storage dependency and must never acquire one. That is what
 * makes "GET /n/{id} does not touch the database" a structural property rather
 * than a convention — see readPage().
 */
final class PageController
{
    /** @var array{js:string,jsSri:string,css:string,cssSri:string} */
    private array $assets;

    public function __construct(
        string $assetsDir,
        private readonly string $i18nDir,
        private readonly string $origin,
    ) {
        $manifest = $assetsDir . '/manifest.json';
        $decoded = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        if (!is_array($decoded) || !isset($decoded['js'], $decoded['jsSri'], $decoded['css'], $decoded['cssSri'])) {
            throw new RuntimeException('assets/manifest.json is missing or malformed — run scripts/build.sh');
        }

        $this->assets = $decoded;
    }

    public function createPage(Request $request): Response
    {
        $locale = I18n::forPath($request->path) ?? I18n::DEFAULT_LOCALE;

        return Response::html($this->homeShell(new I18n($locale, $this->i18nDir)))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * The read shell. No ID validation, no existence check, no storage access.
     *
     * Link prefetchers in Slack, WhatsApp, Outlook, Telegram and every mail
     * scanner will GET this URL before a human ever clicks it. Any lookup here
     * would destroy notes on arrival. There is no exception to this rule.
     *
     * It carries no SEO markup either — no canonical, no hreflang, no JSON-LD,
     * no sitemap entry. Each of those is an instruction to a crawler to come
     * back and fetch the URL again, which is the last thing this page wants.
     */
    public function readPage(): Response
    {
        return Response::html($this->readShell())
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function robots(): Response
    {
        $body = "User-agent: *\n"
            . "Disallow: /n/\n"
            . "Disallow: /api/\n"
            . "Allow: /\n\n"
            . "Sitemap: {$this->origin}/sitemap.xml\n";

        return Response::text($body)->withHeader('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Only the two homepage variants are listed. A note URL must never appear
     * here: it would hand a crawler a link whose fetch is destructive.
     */
    public function sitemap(): Response
    {
        $defaultHref = $this->esc($this->origin . I18n::LOCALES[I18n::DEFAULT_LOCALE]['path']);

        $urls = '';
        foreach (I18n::LOCALES as $meta) {
            $alternates = '';
            foreach (I18n::LOCALES as $alt) {
                $alternates .= sprintf(
                    "\n    <xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\"/>",
                    $this->esc($alt['hreflang']),
                    $this->esc($this->origin . $alt['path']),
                );
            }
            $alternates .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$defaultHref}\"/>";

            $loc = $this->esc($this->origin . $meta['path']);
            $urls .= "\n  <url>\n    <loc>{$loc}</loc>{$alternates}"
                . "\n    <changefreq>monthly</changefreq>\n    <priority>1.0</priority>\n  </url>";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">'
            . $urls . "\n</urlset>\n";

        return Response::xml($xml)->withHeader('Cache-Control', 'public, max-age=86400');
    }

    private function homeShell(I18n $i18n): string
    {
        $lang = $this->esc($i18n->htmlLang());
        $title = $this->esc($i18n->t('seo.ogtitle'));
        $description = $this->esc($i18n->t('seo.description'));
        $canonical = $this->esc($this->origin . $i18n->path());
        $defaultHref = $this->esc($this->origin . I18n::LOCALES[I18n::DEFAULT_LOCALE]['path']);

        $alternates = '';
        foreach (I18n::LOCALES as $meta) {
            $alternates .= sprintf(
                "\n<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\">",
                $this->esc($meta['hreflang']),
                $this->esc($this->origin . $meta['path']),
            );
        }
        $alternates .= "\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"{$defaultHref}\">";

        $head = $this->head($title, $description)
            . "\n<link rel=\"canonical\" href=\"{$canonical}\">"
            . $alternates
            . "\n<meta property=\"og:type\" content=\"website\">"
            . "\n<meta property=\"og:title\" content=\"{$title}\">"
            . "\n<meta property=\"og:description\" content=\"{$description}\">"
            . "\n<meta property=\"og:url\" content=\"{$canonical}\">"
            . "\n<meta name=\"twitter:card\" content=\"summary\">"
            . "\n" . $this->jsonLd($i18n);

        // The bundle replaces the contents of #app. Everything below it is
        // server rendered and stays put, so a crawler — and any visitor whose
        // bundle has not loaded yet — sees real content rather than an empty
        // div. Indexable copy cannot depend on JavaScript having run.
        //
        // The .page wrapper is what centres and pads the column. It has to sit
        // outside <main>, because the about section and the language footer are
        // siblings of <main> — putting the layout on <main> itself left them
        // flush against the left edge of the viewport and running off the right.
        $body = "<div class=\"page\">\n"
            . "<main id=\"app\">\n"
            . '<h1 class="title">' . $this->esc($i18n->t('create.title')) . "</h1>\n"
            . '<p class="tagline">' . $this->esc($i18n->t('create.tagline')) . "</p>\n"
            . "<noscript>\n<p class=\"notice notice-danger\">"
            . $this->esc($i18n->t('common.unsupported')) . "</p>\n</noscript>\n"
            . "</main>\n"
            . $this->aboutSection($i18n) . "\n"
            . $this->languageNav($i18n) . "\n"
            . "</div>";

        return $this->document($lang, $head, $body);
    }

    private function readShell(): string
    {
        // Nothing here is worth translating server-side because there is
        // nothing here at all; the bundle picks a language from the browser.
        $i18n = new I18n(I18n::DEFAULT_LOCALE, $this->i18nDir);

        $head = $this->head($this->esc($i18n->t('read.title')), '')
            . "\n<meta name=\"robots\" content=\"noindex, nofollow\">";

        $body = "<div class=\"page\">\n<main id=\"app\"></main>\n"
            . "<noscript>\n<p class=\"notice notice-danger\">"
            . $this->esc($i18n->t('common.unsupported')) . "</p>\n</noscript>\n</div>";

        return $this->document('en', $head, $body);
    }

    private function head(string $title, string $description): string
    {
        $desc = $description === '' ? '' : "\n<meta name=\"description\" content=\"{$description}\">";

        return '<meta charset="utf-8">'
            . "\n" . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . "\n<title>{$title}</title>"
            . $desc
            . "\n<link rel=\"stylesheet\" href=\"/assets/{$this->assets['css']}\" "
            . "integrity=\"{$this->assets['cssSri']}\" crossorigin=\"anonymous\">";
    }

    private function document(string $lang, string $head, string $body): string
    {
        return "<!DOCTYPE html>\n<html lang=\"{$lang}\">\n<head>\n{$head}\n</head>\n<body>\n{$body}\n"
            . "<script src=\"/assets/{$this->assets['js']}\" integrity=\"{$this->assets['jsSri']}\" "
            . "crossorigin=\"anonymous\" defer></script>\n</body>\n</html>\n";
    }

    private function aboutSection(I18n $i18n): string
    {
        $steps = '';
        for ($n = 1; $n <= 4; $n++) {
            $steps .= "\n<li>" . $this->esc($i18n->t("about.how.{$n}")) . '</li>';
        }

        $faq = '';
        for ($n = 1; $n <= 5; $n++) {
            $faq .= "\n<dt>" . $this->esc($i18n->t("faq.q{$n}")) . '</dt>'
                . "\n<dd>" . $this->esc($i18n->t("faq.a{$n}")) . '</dd>';
        }

        return "<section class=\"prose\">\n"
            . '<h2>' . $this->esc($i18n->t('about.how.title')) . "</h2>\n"
            . "<ol>{$steps}\n</ol>\n"
            . '<h2>' . $this->esc($i18n->t('about.why.title')) . "</h2>\n"
            . '<p>' . $this->esc($i18n->t('about.why.body')) . "</p>\n"
            . "<h2>FAQ</h2>\n<dl>{$faq}\n</dl>\n</section>";
    }

    /**
     * Real anchors, not buttons. A crawler follows links; it does not click a
     * language switcher wired up in JavaScript.
     */
    private function languageNav(I18n $i18n): string
    {
        $links = '';
        foreach (I18n::LOCALES as $code => $meta) {
            if ($code === $i18n->locale()) {
                continue;
            }
            $links .= sprintf(
                '<a class="btn-link" href="%s" hreflang="%s" lang="%s">%s</a>',
                $this->esc($meta['path']),
                $this->esc($meta['hreflang']),
                $this->esc($meta['htmlLang']),
                $this->esc($meta['label']),
            );
        }

        return "<footer id=\"lang\" class=\"footer\">{$links}</footer>";
    }

    /**
     * JSON-LD sits in a script element with a non-JavaScript type, which
     * browsers treat as a data block and never execute — so `script-src 'self'`
     * needs no loosening, and no nonce or hash is required.
     *
     * JSON_HEX_TAG is not optional: without it a `</script` sequence inside any
     * translated string would close the element early and spill the remainder
     * into the document as markup.
     */
    private function jsonLd(I18n $i18n): string
    {
        $faq = [];
        for ($n = 1; $n <= 5; $n++) {
            $faq[] = [
                '@type'          => 'Question',
                'name'           => $i18n->t("faq.q{$n}"),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i18n->t("faq.a{$n}")],
            ];
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'               => 'WebApplication',
                    '@id'                 => $this->origin . '/#webapp',
                    'name'                => 'note.my',
                    'url'                 => $this->origin . $i18n->path(),
                    'description'         => $i18n->t('seo.description'),
                    'applicationCategory' => 'SecurityApplication',
                    'operatingSystem'     => 'Any',
                    'browserRequirements' => 'Requires JavaScript and the Web Crypto API',
                    'inLanguage'          => $i18n->htmlLang(),
                    'isAccessibleForFree' => true,
                    'license'             => 'https://www.gnu.org/licenses/agpl-3.0.html',
                    // No aggregateRating and no reviewCount. Inventing ratings
                    // to win a rich result is exactly the sort of unverifiable
                    // claim this project has no business making — and Google
                    // penalises self-serving markup anyway.
                    'offers'              => [
                        '@type'         => 'Offer',
                        'price'         => '0',
                        'priceCurrency' => 'USD',
                    ],
                ],
                [
                    '@type'      => 'FAQPage',
                    '@id'        => $this->origin . $i18n->path() . '#faq',
                    'inLanguage' => $i18n->htmlLang(),
                    'mainEntity' => $faq,
                ],
            ],
        ];

        $json = json_encode(
            $graph,
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return "<script type=\"application/ld+json\">{$json}</script>";
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
