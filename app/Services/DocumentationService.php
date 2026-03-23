<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class DocumentationService
{
    private function docsBasePath(): string
    {
        return base_path('docs');
    }

    /**
     * Resolve a user-supplied relative path to a real .md file under /docs, or null if invalid.
     */
    public function resolvePath(?string $relative): ?string
    {
        $base = realpath($this->docsBasePath());
        if ($base === false) {
            return null;
        }

        $relative = $relative !== null && $relative !== '' ? $relative : 'README.md';
        $relative = str_replace(["\0", '\\'], ['', '/'], $relative);
        if (str_contains($relative, '..')) {
            return null;
        }

        $full = realpath($base.DIRECTORY_SEPARATOR.$relative);
        if ($full === false || ! str_starts_with($full, $base)) {
            return null;
        }

        if (! str_ends_with(strtolower($full), '.md')) {
            return null;
        }

        return $full;
    }

    /**
     * @return list<string> Paths relative to docs/, sorted
     */
    public function listMarkdownFiles(): array
    {
        $base = $this->docsBasePath();
        if (! is_dir($base)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with(strtolower($file->getFilename()), '.md')) {
                continue;
            }
            $full = $file->getRealPath();
            if ($full === false) {
                continue;
            }
            $rel = ltrim(str_replace($base, '', $full), DIRECTORY_SEPARATOR);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            $paths[] = $rel;
        }

        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($paths);
    }

    public function readMarkdown(string $absolutePath): string
    {
        return File::get($absolutePath);
    }

    public function titleFromPath(string $relativePath): string
    {
        $base = basename($relativePath, '.md');

        return str_replace(['_', '-'], ' ', $base);
    }

    public function markdownToHtml(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => true,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $converter = new MarkdownConverter($environment);

        return $converter->convert($markdown)->getContent();
    }

    /**
     * Rewrite relative .md links in rendered HTML to point at the documentation route with ?doc=.
     */
    public function rewriteMarkdownLinks(string $html, string $currentRelativePath, string $documentationUrl): string
    {
        return (string) preg_replace_callback(
            '/href="([^"]+)"/',
            function (array $m) use ($currentRelativePath, $documentationUrl) {
                $href = $m[1];

                if ($href === '' || str_starts_with($href, '#')) {
                    return $m[0];
                }
                if (preg_match('#^https?://#', $href) || str_starts_with($href, 'mailto:')) {
                    return $m[0];
                }
                if (! str_ends_with(strtolower($href), '.md')) {
                    return $m[0];
                }

                $resolved = $this->resolveRelativeDocumentationPath($currentRelativePath, $href);
                if ($resolved === null) {
                    return $m[0];
                }

                $query = http_build_query(['doc' => $resolved]);

                return 'href="'.$documentationUrl.'?'.$query.'"';
            },
            $html
        );
    }

    /**
     * Resolve href relative to current doc (handles ../ and same-dir).
     */
    public function resolveRelativeDocumentationPath(string $currentRelativePath, string $href): ?string
    {
        $href = str_replace('\\', '/', $href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $dir = dirname(str_replace('\\', '/', $currentRelativePath));
        if ($dir === '.') {
            $dir = '';
        }
        $combined = ($dir !== '' ? $dir.'/' : '').$href;

        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($parts) === 0) {
                    return null;
                }
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        $resolved = implode('/', $parts);

        $base = realpath($this->docsBasePath());
        if ($base === false) {
            return null;
        }
        $full = realpath($base.DIRECTORY_SEPARATOR.$resolved);

        if ($full === false || ! str_starts_with($full, $base) || ! str_ends_with(strtolower($full), '.md')) {
            return null;
        }

        return ltrim(str_replace($base, '', $full), DIRECTORY_SEPARATOR);
    }

    public function relativePathFromAbsolute(string $absolutePath): string
    {
        $base = realpath($this->docsBasePath());
        if ($base === false) {
            return 'README.md';
        }

        $rel = ltrim(str_replace($base, '', $absolutePath), DIRECTORY_SEPARATOR);

        return str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    }
}
