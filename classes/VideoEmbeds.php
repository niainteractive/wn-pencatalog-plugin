<?php namespace Niainteractive\PenCatalog\Classes;

/**
 * Turns whatever is pasted into an item's Videos box into embeddable snippets.
 *
 * The box takes raw embed code - the "Share, then Embed" markup from YouTube, Vimeo and friends -
 * with as many videos per record as you like. It also accepts a bare watch link for the common
 * services, because nobody should have to hunt for the embed markup when the URL is right there.
 *
 * Everything that comes out is sanitised. This is the one field on the record that the public
 * detail page prints without escaping, so it is a stored-XSS hole by definition: only tags that
 * can actually display a player survive, and no script, no on* handlers and no javascript:/data:
 * URLs get through. The trade-off is that embeds which rely on JavaScript to build their player
 * (X/Twitter, Instagram, Facebook) will not work - use the iframe-style embeds.
 */
class VideoEmbeds
{
    /** tag => the attributes kept. Anything not listed is dropped. */
    const ALLOWED = [
        'iframe'     => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy', 'style', 'class'],
        'video'      => ['src', 'width', 'height', 'controls', 'poster', 'preload', 'loop', 'muted', 'playsinline', 'class', 'style'],
        'source'     => ['src', 'type'],
        'embed'      => ['src', 'type', 'width', 'height', 'allowfullscreen', 'class'],
        'object'     => ['data', 'type', 'width', 'height', 'class'],
        'param'      => ['name', 'value'],
        'figure'     => ['class', 'style'],
        'figcaption' => ['class', 'style'],
        'a'          => ['href', 'title', 'target', 'rel'],
        'p'          => ['class', 'style'],
        'div'        => ['class', 'style'],
        'span'       => ['class', 'style'],
        'br'         => [],
        'strong'     => [],
        'em'         => [],
        'small'      => [],
    ];

    /** Tags that are players in their own right - each one becomes an entry in the list. */
    const PLAYER_TAGS = ['iframe', 'video', 'embed', 'object'];

    /** Players that are pointless without a source of their own (object can use a child param). */
    const NEEDS_SOURCE = ['iframe', 'video', 'embed'];

    /** Tags whose contents must not survive as visible text. */
    const DISCARD = ['script', 'style', 'noscript', 'template', 'form', 'input', 'button'];

    /** Void elements - no closing tag. */
    const VOID = ['br', 'embed', 'source', 'param'];

    /** Attributes that carry a URL and therefore need a scheme check. */
    const URL_ATTRS = ['src', 'href', 'data', 'poster'];

    /**
     * @param  string|null $text the raw contents of the Videos box
     * @return array  one sanitised HTML snippet per video, in the order they were entered
     */
    public static function parse($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }

        $text = self::expandBareUrls($text);

        $fragments = self::splitPlayers($text);

        if (!$fragments) {
            // Nothing player-shaped. Keep the whole thing (sanitised) rather than dropping what
            // someone typed - a plain link or a hand-written snippet still deserves to show.
            $fragments = [$text];
        }

        $out = [];
        foreach ($fragments as $fragment) {
            $clean = trim(self::clean($fragment));
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** Pull each top-level player element out of the text, in order. */
    protected static function splitPlayers($text)
    {
        $pattern = '/<(iframe|video|object)\b[^>]*>.*?<\/\1>|<(iframe|video|embed|object)\b[^>]*\/?>/is';

        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $match) {
            $out[] = trim($match[0]);
        }

        return $out;
    }

    /**
     * A bare link on its own line becomes the embed for that service. Ten seconds of typing
     * instead of twenty minutes of hunting through a Share dialog.
     */
    protected static function expandBareUrls($text)
    {
        $lines = preg_split('/\R/', $text);
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || strpos($trimmed, '<') !== false) {
                $out[] = $line;
                continue;
            }

            $url = preg_match('#^https?://#i', $trimmed) ? $trimmed : (preg_match('#^www\.#i', $trimmed) ? 'https://' . $trimmed : '');

            if ($url === '') {
                $out[] = $line;
                continue;
            }

            $player = self::playerFor($url);
            $out[] = $player === null ? $line : self::iframe($player);
        }

        return implode("\n", $out);
    }

    /** The embed URL for a known service, or null if this is not one. */
    protected static function playerFor($url)
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('#^www\.#', '', $parts['host']));
        $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if ($host === 'youtu.be' && $path !== '') {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode(strtok($path, '/'));
        }

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === 'watch' && !empty($query['v'])) {
                return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($query['v']);
            }
            if (preg_match('#^(embed|shorts|v)/([A-Za-z0-9_-]+)#', $path, $m)) {
                return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($m[2]);
            }
        }

        if ($host === 'vimeo.com' && preg_match('#^(\d+)#', $path, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        if ($host === 'dailymotion.com' && preg_match('#^video/([A-Za-z0-9]+)#', $path, $m)) {
            return 'https://www.dailymotion.com/embed/video/' . $m[1];
        }

        if (in_array($host, ['bilibili.com', 'm.bilibili.com'], true) && preg_match('#^video/(BV[A-Za-z0-9]+)#', $path, $m)) {
            return 'https://player.bilibili.com/player.html?bvid=' . $m[1] . '&high_quality=1';
        }

        return null;
    }

    protected static function iframe($src)
    {
        return '<iframe src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="640" height="360" '
            . 'frameborder="0" loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; '
            . 'gyroscope; picture-in-picture" allowfullscreen></iframe>';
    }

    /** Rebuild a fragment from the whitelist, dropping everything else. */
    protected static function clean($html)
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div data-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($document->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('data-root') === '1') {
                $root = $div;
                break;
            }
        }

        if (!$root) {
            return '';
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= self::render($child);
        }

        return $out;
    }

    protected static function render(\DOMNode $node)
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue, ENT_QUOTES, 'UTF-8');
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DISCARD, true)) {
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::render($child);
        }

        // Unknown tag: keep the words, lose the wrapper.
        if (!isset(self::ALLOWED[$tag])) {
            return $inner;
        }

        $attributes = '';
        $hasLoading = false;
        $hasSource = false;

        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (!in_array($name, self::ALLOWED[$tag], true) || strpos($name, 'on') === 0 || $value === '') {
                continue;
            }

            if (in_array($name, self::URL_ATTRS, true)) {
                if (!self::safeUrl($value)) {
                    continue;
                }
                $hasSource = true;
            }

            if ($tag === 'param' && strtolower($node->getAttribute('name')) === 'movie' && !self::safeUrl($value)) {
                continue;
            }

            if ($name === 'loading') {
                $hasLoading = true;
            }

            if ($name === 'style' && !self::safeStyle($value)) {
                continue;
            }

            $attributes .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        // A player whose source was rejected is only an empty box - drop it rather than leave a
        // blank frame sitting on the page.
        if (in_array($tag, self::NEEDS_SOURCE, true) && !$hasSource) {
            return '';
        }

        // Ask the browser not to fetch a player that is far down the page.
        if (in_array($tag, ['iframe', 'video'], true) && !$hasLoading) {
            $attributes .= ' loading="lazy"';
        }

        if (in_array($tag, self::VOID, true)) {
            return '<' . $tag . $attributes . '>';
        }

        return '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
    }

    protected static function safeUrl($value)
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#i', $value)) {
            return false;
        }

        // Absolute http(s), protocol-relative, or a path on this site.
        return (bool) preg_match('#^(https?:)?//#i', $value) || strpos($value, '/') === 0;
    }

    protected static function safeStyle($value)
    {
        return stripos($value, 'javascript:') === false
            && stripos($value, 'expression(') === false
            && stripos($value, 'url(') === false;
    }
}
