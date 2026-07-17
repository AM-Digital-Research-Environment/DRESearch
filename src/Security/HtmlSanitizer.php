<?php
declare(strict_types=1);

namespace DRESearch\Security;

/** Conservative sanitizer for editor-authored block introductions. */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'blockquote', 'code', 'a'];
    private const DROP_WITH_CONTENTS = ['script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math'];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="dre-sanitize-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('dre-sanitize-root');
        if ($root === null) {
            return '';
        }

        self::clean($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }
        return $out;
    }

    private static function clean(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    if (in_array($tag, self::DROP_WITH_CONTENTS, true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    // Clean descendants before unwrapping this presentation-only
                    // container; moved nodes have already passed the same policy.
                    self::clean($child);
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    if ($tag !== 'a' || !in_array($name, ['href', 'title'], true)) {
                        $child->removeAttributeNode($attribute);
                    }
                }
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href'));
                    if (!preg_match('#^https?://#i', $href) && !str_starts_with($href, '/')) {
                        $child->removeAttribute('href');
                    } else {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }
            }
            self::clean($child);
        }
    }
}
