<?php

namespace OgImage\Twig;

use Cecil\Asset;
use Cecil\Builder;
use Cecil\Cache;
use Cecil\Collection\Page\Page;
use Cecil\Collection\Page\Type;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Open Graph image Twig extension: `og_image()` function.
 */
class Extension extends AbstractExtension
{
    /** Open Graph image size. */
    private const WIDTH = 1200;
    private const HEIGHT = 628;

    /** Font sizes of the title (the biggest that fits is used) and of the footer. */
    private const TITLE_SIZES = [64, 56, 48, 42, 36];
    private const FOOTER_SIZE = 22;

    /** Options of the style of the images of the pages, which can be overridden by section and by page. */
    private const STYLE_OPTIONS = ['background', 'color', 'fonts', 'padding', 'by', 'site_title'];

    /** @var Builder */
    protected $builder;

    /** Is the missing GD extension already reported? */
    private bool $reported = false;

    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('og_image', [$this, 'ogImage']),
        ];
    }

    /**
     * Generates the Open Graph image of a page (title, author and site title),
     * of a section (title and description, like the image of the site), or the image of the site (title and description) without page, for the homepage,
     * and for pages out of the `ogimage.sections` sections.
     *
     * The style options of the site can be overridden by those of the section of the page
     * (`ogimage.sections.<section>`), then by those of the page (`ogimage` map in its front matter).
     *
     * Returns null if there is nothing to generate: the page (or the site) has its own image,
     * the generation is disabled (`ogimage.enabled`, or `ogimage: false` in the front matter),
     * or the image can't be generated (GD with FreeType is required).
     */
    public function ogImage(?Page $page = null): ?Asset
    {
        $config = $this->builder->getConfig();
        $language = $page?->getVariable('language') ?? $config->getLanguageDefault();

        if (!$config->isEnabled('ogimage', $language)) {
            return null;
        }
        $sections = $this->sections((array) ($config->get('ogimage.sections', $language) ?? []));
        // like the pages, the images of a language are prefixed with its code, except for the default language
        $prefix = $language !== $config->getLanguageDefault() || $config->isEnabled('language.prefix') ? "/$language" : '';
        if ($page !== null) {
            if ($page->getVariable('multilingual') === false) {
                $prefix = '';
            }
            if (!empty($page->getVariable('image')) || $page->getVariable('ogimage') === false) {
                return null;
            }
            if (!$this->hasOwnImage($page, $sections)) {
                $page = null;
            }
        }
        if ($page === null && !empty($config->get('image', $language))) {
            return null;
        }
        if (!\function_exists('imagettftext')) {
            if (!$this->reported) {
                $this->builder->getLogger()->warning('Open Graph image: the GD extension with FreeType support is required.');
                $this->reported = true;
            }

            return null;
        }

        // style options: those of the site (read one by one, to get the localized value of each of them),
        // overridden by those of the section of the page, then by those of the page
        $options = [];
        foreach (self::STYLE_OPTIONS as $key) {
            $options[$key] = $config->get("ogimage.$key", $language);
        }
        if ($page !== null) {
            $options = $this->override($options, $sections[(string) $page->getSection()] ?? []);
            $frontMatter = $page->getVariable('ogimage');
            if (\is_array($frontMatter)) {
                $options = $this->override($options, $frontMatter);
            }
        }

        // texts
        $siteTitle = (string) $config->get('title', $language);
        if ($page !== null && $page->getType() === Type::SECTION->value) {
            // like the image of the site: title and description of the section (or of the site)
            $title = (string) $page->getVariable('title');
            $description = (string) ($page->getVariable('description') ?: $config->get('description', $language));
            $footer = ['', $description, ''];
            $path = $prefix . '/' . trim((string) $page->getPath(), '/') . '/og.png';
        } elseif ($page !== null) {
            // the site title can be hidden from the footer (e.g. when it is the name of the author)
            if (!($options['site_title'] ?? true)) {
                $siteTitle = '';
            }
            $title = (string) $page->getVariable('title');
            $author = $page->getVariable('author') ?? $config->get('author', $language);
            $author = \is_array($author) ? (string) ($author['name'] ?? '') : (string) $author;
            $by = trim((string) ($options['by'] ?? ''));
            $footer = $author !== '' ? [$by !== '' ? "$by " : '', $author, $siteTitle] : ['', '', $siteTitle];
            $path = $prefix . '/' . trim((string) $page->getPath(), '/') . '/og.png';
        } else {
            $title = $siteTitle;
            $footer = ['', (string) $config->get('description', $language), ''];
            $path = $prefix . '/og.png';
        }
        $path = str_replace('//', '/', $path);

        // style
        $padding = array_map('intval', (array) ($options['padding'] ?? [])) + ['top' => 105, 'right' => 110, 'bottom' => 100, 'left' => 110];
        $color = $this->parseColor((string) ($options['color'] ?? ''));
        $fonts = (array) ($options['fonts'] ?? []);
        try {
            $background = new Asset($this->builder, (string) ($options['background'] ?? 'ogimage/background.png'));
            $regular = new Asset($this->builder, (string) ($fonts['regular'] ?? 'ogimage/fonts/GoogleSansCode-Regular.ttf'));
            $bold = new Asset($this->builder, (string) ($fonts['bold'] ?? 'ogimage/fonts/GoogleSansCode-Bold.ttf'));
        } catch (\Exception $e) {
            $this->builder->getLogger()->warning(\sprintf('Open Graph image: unable to generate "%s" (%s).', $path, $e->getMessage()));

            return null;
        }

        $hash = hash('xxh128', implode('|', [$title, ...$footer, ...$color, ...$padding, $background['hash'], $regular['hash'], $bold['hash']]));
        $cache = new Cache($this->builder, 'ogimage');
        $cacheKey = 'og_' . $hash;
        if (!$cache->has($cacheKey)) {
            $png = $this->draw($background['file'], $regular['file'], $bold['file'], $title, $footer, $color, $padding);
            if ($png === null) {
                $this->builder->getLogger()->warning(\sprintf('Open Graph image: unable to generate "%s" (background "%s" is not a valid image).', $path, $background['path']));

                return null;
            }
            $cache->set($cacheKey, base64_encode($png));
            $this->builder->getLogger()->debug(\sprintf('Open Graph image "%s" generated.', $path));
        }
        $png = base64_decode((string) $cache->get($cacheKey));

        // stores the image as the content of an asset, saved with the other assets
        $contentFile = (new Cache($this->builder, 'assets'))->getContentFile($path);
        if (!is_file($contentFile) || hash_file('xxh128', $contentFile) !== hash('xxh128', $png)) {
            if (!is_dir(\dirname($contentFile))) {
                mkdir(\dirname($contentFile), 0777, true);
            }
            file_put_contents($contentFile, $png);
        }
        $background['path'] = $path;
        $background['content'] = $png;
        $background['hash'] = hash('xxh128', $png);
        $background['size'] = \strlen($png);
        $background['width'] = self::WIDTH;
        $background['height'] = self::HEIGHT;

        return $background;
    }

    /**
     * Has the page its own image (otherwise the image of the site is used)?
     *
     * @param array<string, array<string, mixed>> $sections sections of the pages with their own image (all pages if empty)
     */
    private function hasOwnImage(Page $page, array $sections): bool
    {
        if ($page->getType() === Type::HOMEPAGE->value) {
            return false;
        }

        return $sections === [] || \array_key_exists((string) $page->getSection(), $sections);
    }

    /**
     * Normalizes the `ogimage.sections` option, a list of names (`[blog, docs]`) and/or a map of names
     * and options (`{blog: {color: '#fff'}, docs: ~}`), to a map of names and options.
     * A section set to `false` is ignored.
     *
     * @param array<int|string, mixed> $sections
     *
     * @return array<string, array<string, mixed>>
     */
    private function sections(array $sections): array
    {
        $normalized = [];
        foreach ($sections as $key => $value) {
            if (\is_int($key)) {
                if (\is_string($value) && $value !== '') {
                    $normalized[$value] ??= [];
                }
                continue;
            }
            if ($value === false) {
                continue;
            }
            $normalized[$key] = \is_array($value) ? $value : [];
        }

        return $normalized;
    }

    /**
     * Overrides style options: only the style options are taken, null values are ignored,
     * and the maps (`fonts`, `padding`) are merged key by key.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function override(array $options, array $overrides): array
    {
        foreach (self::STYLE_OPTIONS as $key) {
            if (!isset($overrides[$key])) {
                continue;
            }
            $options[$key] = \is_array($overrides[$key]) && \is_array($options[$key] ?? null)
                ? array_replace($options[$key], array_filter($overrides[$key], fn ($value) => $value !== null))
                : $overrides[$key];
        }

        return $options;
    }

    /**
     * Converts a hexadecimal color (`#rgb` or `#rrggbb`) to red, green and blue values (black if invalid).
     *
     * @return array{int, int, int}
     */
    private function parseColor(string $color): array
    {
        $hex = ltrim(trim($color), '#');
        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (\strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * Draws the Open Graph image and returns its PNG binary.
     *
     * @param array{string, string, string} $footer  prefix and bold text of the bottom left line, bold text of the bottom right
     * @param array{int, int, int}          $color   red, green and blue values of the text color
     * @param array<string, int>            $padding space around the text (top, right, bottom, left)
     */
    private function draw(string $backgroundFile, string $regularFont, string $boldFont, string $title, array $footer, array $color, array $padding): ?string
    {
        $background = @imagecreatefromstring((string) file_get_contents($backgroundFile));
        if ($background === false) {
            return null;
        }
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($image, $background, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, imagesx($background), imagesy($background));
        imagedestroy($background);
        $ink = imagecolorallocate($image, ...$color);

        // text box
        $left = $padding['left'];
        $right = self::WIDTH - $padding['right'];
        $top = $padding['top'];
        $bottom = self::HEIGHT - $padding['bottom'];
        $maxWidth = $right - $left;

        // title: the biggest font size that fits above the footer
        $titleBottom = $bottom - 70;
        $size = self::TITLE_SIZES[0];
        $lines = [];
        $lineHeight = 0;
        foreach (self::TITLE_SIZES as $size) {
            $lines = $this->wrap($title, $boldFont, $size, $maxWidth);
            $lineHeight = (int) round($size * 1.35);
            if (\count($lines) * $lineHeight <= $titleBottom - $top) {
                break;
            }
        }
        $maxLines = max(1, intdiv($titleBottom - $top, $lineHeight));
        if (\count($lines) > $maxLines) {
            $lines = \array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->ellipsis($lines[$maxLines - 1] . '…', $boldFont, $size, $maxWidth);
        }
        $y = $top + $size;
        foreach ($lines as $line) {
            imagettftext($image, $size, 0, $left, $y, $ink, $boldFont, $line);
            $y += $lineHeight;
        }

        // footer: "by <author>" (or the site description) on the left, site title on the right
        [$prefix, $text, $aside] = $footer;
        $asideWidth = 0;
        if ($aside !== '') {
            $box = imagettfbbox(self::FOOTER_SIZE, 0, $boldFont, $aside);
            $asideWidth = $box[2] - $box[0];
            imagettftext($image, self::FOOTER_SIZE, 0, $right - $asideWidth, $bottom, $ink, $boldFont, $aside);
        }
        $x = $left;
        if ($prefix !== '') {
            $box = imagettfbbox(self::FOOTER_SIZE, 0, $regularFont, $prefix);
            imagettftext($image, self::FOOTER_SIZE, 0, $x, $bottom, $ink, $regularFont, $prefix);
            $x += $box[2] - $box[0];
        }
        if ($text !== '') {
            $font = $prefix !== '' ? $boldFont : $regularFont;
            $text = $this->ellipsis($text, $font, self::FOOTER_SIZE, $right - $x - ($asideWidth > 0 ? $asideWidth + 40 : 0));
            imagettftext($image, self::FOOTER_SIZE, 0, $x, $bottom, $ink, $font, $text);
        }

        ob_start();
        imagepng($image, null, 9);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * Wraps a text in lines that fit in a width.
     *
     * @return string[]
     */
    private function wrap(string $text, string $font, float $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($line !== '' && ($box[2] - $box[0]) > $width) {
                $lines[] = $line;
                $line = $word;
                continue;
            }
            $line = $candidate;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Truncates a text with an ellipsis to fit in a width.
     */
    private function ellipsis(string $text, string $font, float $size, int $width): string
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if (($box[2] - $box[0]) <= $width) {
            return $text;
        }
        $text = rtrim($text, '…');
        while (mb_strlen($text) > 1) {
            $text = mb_substr($text, 0, -1);
            $box = imagettfbbox($size, 0, $font, "$text…");
            if (($box[2] - $box[0]) <= $width) {
                break;
            }
        }

        return rtrim($text) . '…';
    }
}
