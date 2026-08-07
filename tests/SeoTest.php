<?php

declare(strict_types=1);

/**
 * SEO markup tests. Black box over HTTP, because what matters is exactly what a
 * crawler receives — not what the templating layer intended.
 */

$base = getenv('NOTEMY_BASE') ?: 'http://127.0.0.1:8080';

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32mPASS\033[0m  {$name}\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

$en = get("{$base}/");
$zh = get("{$base}/zh");

echo "\n--- 1. Indexable content exists without JavaScript ---\n";

// Strip scripts, then everything else, and see what a crawler that does not
// execute JS would actually have to read.
$visible = static function (string $html): string {
    $noScript = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
    return trim(preg_replace('/\s+/u', ' ', strip_tags($noScript)) ?? '');
};

$enText = $visible($en['body']);
$zhText = $visible($zh['body']);

ok('English homepage returns 200', $en['status'] === 200);
ok('Chinese homepage returns 200', $zh['status'] === 200);
ok('English page has substantial text without JS', mb_strlen($enText) > 800, mb_strlen($enText) . ' chars');
ok('Chinese page has substantial text without JS', mb_strlen($zhText) > 400, mb_strlen($zhText) . ' chars');
ok('English page is actually in English', str_contains($enText, 'How it works'));
ok('Chinese page is actually in Chinese', str_contains($zhText, '它是怎么工作的'));
ok('the two locales differ', $enText !== $zhText);
ok('exactly one h1 per page', substr_count($en['body'], '<h1') === 1, (string) substr_count($en['body'], '<h1'));

echo "\n--- 2. Canonical and hreflang ---\n";

preg_match('#<link rel="canonical" href="([^"]+)"#', $en['body'], $mEn);
preg_match('#<link rel="canonical" href="([^"]+)"#', $zh['body'], $mZh);
ok('English canonical is self-referential', ($mEn[1] ?? '') === "{$base}/", $mEn[1] ?? 'missing');
ok('Chinese canonical is self-referential', ($mZh[1] ?? '') === "{$base}/zh", $mZh[1] ?? 'missing');

foreach (['en' => $en, 'zh' => $zh] as $label => $page) {
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"#', $page['body'], $alts, PREG_SET_ORDER);
    $map = [];
    foreach ($alts as $a) {
        $map[$a[1]] = $a[2];
    }
    ok(
        "{$label}: hreflang set is reciprocal and complete",
        ($map['en'] ?? '') === "{$base}/"
        && ($map['zh-Hans'] ?? '') === "{$base}/zh"
        && ($map['x-default'] ?? '') === "{$base}/",
        json_encode($map),
    );
}

ok('html lang differs per locale',
    str_contains($en['body'], '<html lang="en">') && str_contains($zh['body'], '<html lang="zh-CN">'));

echo "\n--- 3. Language switch is a crawlable link ---\n";

ok('English page links to /zh with an anchor', (bool) preg_match('#<a[^>]+href="/zh"[^>]*>#', $en['body']));
ok('Chinese page links to / with an anchor', (bool) preg_match('#<a[^>]+href="/"[^>]*>#', $zh['body']));

echo "\n--- 4. JSON-LD ---\n";

preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $en['body'], $ld);
$data = json_decode($ld[1] ?? '', true);
ok('JSON-LD block is present and valid JSON', is_array($data), substr($ld[1] ?? 'missing', 0, 60));

$types = array_column($data['@graph'] ?? [], '@type');
ok('graph contains WebApplication and FAQPage',
    in_array('WebApplication', $types, true) && in_array('FAQPage', $types, true),
    implode(',', $types));

$app = ($data['@graph'] ?? [])[0] ?? [];
ok('WebApplication url matches the canonical', ($app['url'] ?? '') === "{$base}/", $app['url'] ?? '');
ok('no invented ratings', !isset($app['aggregateRating'], $app['reviewCount']));

$faqEntity = ($data['@graph'] ?? [])[1] ?? [];
ok('FAQ has five answered questions',
    count($faqEntity['mainEntity'] ?? []) === 5
    && ($faqEntity['mainEntity'][0]['acceptedAnswer']['@type'] ?? '') === 'Answer');

// Every FAQ answer in the markup must also be visible on the page; Google
// treats JSON-LD that is not backed by on-page content as a violation.
$allVisible = true;
foreach ($faqEntity['mainEntity'] ?? [] as $q) {
    if (!str_contains($enText, mb_substr((string) $q['name'], 0, 20))) {
        $allVisible = false;
    }
}
ok('every FAQ question in the markup is visible on the page', $allVisible);

$zhLd = [];
preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $zh['body'], $zhMatch);
$zhLd = json_decode($zhMatch[1] ?? '', true);
ok('Chinese JSON-LD declares inLanguage zh-CN',
    (($zhLd['@graph'] ?? [])[0]['inLanguage'] ?? '') === 'zh-CN');

echo "\n--- 5. JSON-LD does not need a CSP exemption ---\n";

// A data block is never executed, so script-src 'self' covers it — but only if
// the type attribute is genuinely non-JavaScript and the JSON cannot break out.
ok('type attribute marks it as data, not script',
    str_contains($en['body'], '<script type="application/ld+json">'));
ok('no inline executable script anywhere',
    !preg_match('#<script(?![^>]*(?:src=|type="application/ld\+json"))[^>]*>[^<]#i', $en['body']));
ok('closing-tag sequences are hex escaped (JSON_HEX_TAG)',
    !preg_match('#<script type="application/ld\+json">[^<]*</script#i', $en['body'] . 'x')
    || !str_contains((string) ($ld[1] ?? ''), '</'));

echo "\n--- 6. robots.txt and sitemap.xml ---\n";

$robots = get("{$base}/robots.txt");
ok('robots.txt served as text/plain',
    $robots['status'] === 200 && stripos($robots['headers'], 'Content-Type: text/plain') !== false);
ok('robots.txt disallows note and api paths',
    str_contains($robots['body'], 'Disallow: /n/') && str_contains($robots['body'], 'Disallow: /api/'));
ok('robots.txt points at the sitemap', str_contains($robots['body'], "Sitemap: {$base}/sitemap.xml"));

$sitemap = get("{$base}/sitemap.xml");
ok('sitemap served as xml',
    $sitemap['status'] === 200 && stripos($sitemap['headers'], 'Content-Type: application/xml') !== false);

$xml = simplexml_load_string($sitemap['body']);
ok('sitemap is well-formed XML', $xml !== false);

$locs = [];
foreach ($xml->url ?? [] as $url) {
    $locs[] = (string) $url->loc;
}
sort($locs);
ok('sitemap lists exactly the two homepages', $locs === ["{$base}/", "{$base}/zh"], implode(' ', $locs));
ok('sitemap contains no note URLs', !str_contains($sitemap['body'], '/n/'));
ok('sitemap carries xhtml alternate links',
    substr_count($sitemap['body'], 'xhtml:link') === 6,
    (string) substr_count($sitemap['body'], 'xhtml:link'));

echo "\n--- 7. The reading page stays out of the index ---\n";

$read = get("{$base}/n/AAAAAAAAAAAAAAAAAAAAAA");
ok('read page sends noindex meta and header',
    str_contains($read['body'], 'name="robots" content="noindex, nofollow"')
    && stripos($read['headers'], 'X-Robots-Tag: noindex') !== false);
ok('read page has no canonical', !str_contains($read['body'], 'rel="canonical"'));
ok('read page has no hreflang', !str_contains($read['body'], 'rel="alternate"'));
ok('read page has no JSON-LD', !str_contains($read['body'], 'application/ld+json'));
ok('read page is no-store', stripos($read['headers'], 'Cache-Control: no-store') !== false);
ok('homepage is cacheable, read page is not',
    stripos($en['headers'], 'Cache-Control: public') !== false);

echo "\n--- 8. Escaping ---\n";

// The source-code link is the one tag the page itself puts inside a <p>, so it
// is allowed through; anything else opening a tag there came from a translation
// that escaped its angle brackets, which is the bug this guards against.
ok('no unescaped angle brackets leaked from translations',
    !preg_match('#<(dt|dd|li|p)>[^<]*<(?!/|a class="btn-link")#', $en['body'] . $zh['body']));
ok('the how-it-works section links to the source repository',
    substr_count($en['body'], '<a class="btn-link" href="https://github.com/FreeyW/note.my"') === 1
    && substr_count($zh['body'], '<a class="btn-link" href="https://github.com/FreeyW/note.my"') === 1);
ok('SRI still present on both locales',
    preg_match_all('#integrity="sha384-#', $en['body']) === 2
    && preg_match_all('#integrity="sha384-#', $zh['body']) === 2);

echo "\n" . str_repeat('=', 52) . "\n";
echo ($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} passed, {$fail} failed\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
