<?php

namespace ErnestDefoe\Giveaways\Tests;

use ErnestDefoe\Giveaways\Api\Controller\SaveGiveawayController;
use Flarum\Formatter\Formatter;
use Flarum\Locale\TranslatorInterface;
use PHPUnit\Framework\TestCase;

class SaveGiveawayControllerTest extends TestCase
{
    private function controller(): SaveGiveawayController
    {
        return new SaveGiveawayController(
            $this->createMock(TranslatorInterface::class),
            $this->createMock(Formatter::class)
        );
    }

    private function url(string $value): ?string
    {
        $method = new \ReflectionMethod(SaveGiveawayController::class, 'url');
        return $method->invoke($this->controller(), $value);
    }

    public function testAcceptsHttpsUrl(): void
    {
        $this->assertSame(
            'https://example.com/cover.png',
            $this->url('https://example.com/cover.png')
        );
    }

    public function testAcceptsHttpUrl(): void
    {
        $this->assertSame('http://example.com/c', $this->url('http://example.com/c'));
    }

    public function testAcceptsSiteRelativePath(): void
    {
        $this->assertSame('/uploads/cover.png', $this->url('/uploads/cover.png'));
    }

    public function testRejectsJavascriptUrl(): void
    {
        $this->assertNull($this->url('javascript:alert(1)'));
    }

    public function testRejectsDataUrl(): void
    {
        $this->assertNull($this->url('data:text/html;base64,PHNjcmlwdD4='));
    }

    public function testRejectsFileUrl(): void
    {
        $this->assertNull($this->url('file:///etc/passwd'));
    }

    public function testRejectsProtocolRelativeUrl(): void
    {
        $this->assertNull($this->url('//evil.example.com/cover.png'));
    }

    public function testStripsQuoteBreakoutBeforeSchemeCheck(): void
    {
        // Quotes/parens are stripped first; a javascript: URL is still rejected.
        $this->assertNull($this->url('javascript:alert(1);" onerror="x'));
    }

    public function testRejectsEmptyValue(): void
    {
        $this->assertNull($this->url('   '));
    }
}