<?php

declare(strict_types=1);

namespace NoteMy;

use RuntimeException;

/**
 * Server-side reader for the same JSON catalogues the frontend bundles.
 *
 * Sharing one source of truth matters here: the homepage copy is rendered by
 * PHP so crawlers see real text, and re-rendered by TypeScript once the bundle
 * loads. Two copies of the same strings would drift, and a crawler would end up
 * indexing text that no visitor ever sees.
 */
final class I18n
{
    /**
     * Every locale needs a distinct URL. hreflang annotations describe
     * alternate *pages*, so a single URL that swaps language client-side cannot
     * be expressed to a crawler at all.
     *
     * @var array<string,array{path:string,hreflang:string,htmlLang:string,label:string}>
     */
    public const LOCALES = [
        'en' => [
            'path'     => '/',
            'hreflang' => 'en',
            'htmlLang' => 'en',
            'label'    => 'English',
        ],
        'zh-CN' => [
            'path'     => '/zh',
            'hreflang' => 'zh-Hans',   // script subtag, not region: correct for
            'htmlLang' => 'zh-CN',     // Simplified Chinese wherever it is read
            'label'    => '中文',
        ],
    ];

    public const DEFAULT_LOCALE = 'en';

    /** @var array<string,string> */
    private array $catalog;

    public function __construct(private readonly string $locale, string $i18nDir)
    {
        if (!isset(self::LOCALES[$locale])) {
            throw new RuntimeException("Unknown locale: {$locale}");
        }

        $file = $i18nDir . '/' . $locale . '.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($decoded)) {
            throw new RuntimeException("Missing or malformed catalogue: {$file}");
        }

        $this->catalog = $decoded;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function htmlLang(): string
    {
        return self::LOCALES[$this->locale]['htmlLang'];
    }

    public function path(): string
    {
        return self::LOCALES[$this->locale]['path'];
    }

    public function t(string $key): string
    {
        return $this->catalog[$key] ?? $key;
    }

    /** Locale whose path matches a request, or null. */
    public static function forPath(string $path): ?string
    {
        foreach (self::LOCALES as $code => $meta) {
            if ($meta['path'] === $path) {
                return $code;
            }
        }

        return null;
    }
}
