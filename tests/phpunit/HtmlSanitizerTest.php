<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testRemovesScriptsHandlersAndUnsafeLinks(): void
    {
        $html = HtmlSanitizer::sanitize(
            '<div><p onclick="bad()">Intro <span><script>alert(1)</script><em>safe</em></span>'
            . '<a href="javascript:bad()" style="color:red">bad</a></p></div>'
            . '<a href="https://example.test">good</a>',
        );
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<em>safe</em>', $html);
        self::assertStringContainsString('href="https://example.test"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }
}
