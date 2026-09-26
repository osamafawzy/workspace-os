<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * This application runs on a company network with no internet connection.
 *
 * A single stylesheet, font or script pulled from a CDN does not fail loudly
 * there — the page just renders slowly, unstyled, or half broken, and only on
 * the one network nobody develops on. So nothing the browser loads may point
 * off the machine, and this test is what keeps it that way.
 */
class OfflineAssetsTest extends TestCase
{
    /**
     * Ways a page makes the browser fetch something. A URL in a comment or a
     * link somebody clicks is not a fetch; these are.
     */
    private const LOADS = [
        'attribute' => '/\b(?:src|href|srcset|poster|data)\s*=\s*["\']\s*(?:https?:)?\/\/(?!localhost|127\.0\.0\.1)[^"\']+/i',
        'css import' => '/@import\s+(?:url\()?\s*["\']?\s*(?:https?:)?\/\/(?!localhost|127\.0\.0\.1)/i',
        'css url()' => '/url\(\s*["\']?\s*(?:https?:)?\/\/(?!localhost|127\.0\.0\.1)/i',
        'script fetch' => '/\b(?:fetch|import)\(\s*["\'](?:https?:)?\/\/(?!localhost|127\.0\.0\.1)/i',
    ];

    /**
     * Known, checked exceptions: a URL that is present in a file but never
     * requested. Each one needs a test proving it stays dead.
     *
     * @var array<string, string> file (relative, forward slashes) => URL fragment
     */
    private const DEAD_CODE = [
        // EasyMDE, inside Filament's markdown editor, can inject Font Awesome
        // from a CDN — but only when autoDownloadFontAwesome is not false, and
        // Filament passes false. See the test below.
        'public/js/filament/forms/components/markdown-editor.js' => 'maxcdn.bootstrapcdn.com/font-awesome',
    ];

    public function test_nothing_the_browser_loads_comes_from_the_internet(): void
    {
        $offenders = [];

        foreach ($this->files() as $file) {
            $contents = $file->getContents();
            $relative = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());

            foreach (self::LOADS as $kind => $pattern) {
                if (preg_match_all($pattern, $contents, $matches)) {
                    foreach (array_unique($matches[0]) as $match) {
                        if (isset(self::DEAD_CODE[$relative]) && str_contains($match, self::DEAD_CODE[$relative])) {
                            continue;
                        }

                        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                            ." ({$kind}): ".mb_strimwidth(trim($match), 0, 120, '…');
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "These would reach the internet from a browser:\n".implode("\n", $offenders));
    }

    /**
     * Keeps the one exception above honest. If a Filament update stops
     * switching the download off, this fails before any browser tries it.
     */
    public function test_filament_still_stops_the_markdown_editor_fetching_font_awesome(): void
    {
        $script = public_path('js/filament/forms/components/markdown-editor.js');

        if (! is_file($script)) {
            $this->markTestSkipped('Filament assets are not published.');
        }

        $this->assertStringContainsString('autoDownloadFontAwesome:!1', (string) file_get_contents($script));
    }

    public function test_the_scan_would_catch_a_cdn_link(): void
    {
        $page = '<link href="https://fonts.bunny.net/css?family=figtree" rel="stylesheet">'
            .'<script src="//cdn.example.com/lib.js"></script>';

        $this->assertMatchesRegularExpression(self::LOADS['attribute'], $page);
        $this->assertDoesNotMatchRegularExpression(self::LOADS['attribute'], '<a href="/admin">Admin</a>');
        $this->assertDoesNotMatchRegularExpression(
            self::LOADS['css url()'],
            "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'%3E\")",
        );
    }

    /** @return iterable<SplFileInfo> */
    private function files(): iterable
    {
        $directories = array_filter([
            resource_path(),
            ...glob(base_path('Modules/*/resources'), GLOB_ONLYDIR) ?: [],
            public_path('build'),
            public_path('css'),
            public_path('js'),
        ], 'is_dir');

        foreach ($directories as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if (in_array($file->getExtension(), ['php', 'css', 'js', 'html', 'scss', 'vue'], true)) {
                    yield $file;
                }
            }
        }
    }
}
