<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuietMetrics\Client;

final class HtmlPageResponseTest extends TestCase
{
    public static function responses(): array
    {
        return [
            ['GET', 200, 'text/html; charset=UTF-8', null, true],
            ['GET', 404, 'text/html', null, true],
            ['GET', 500, 'application/xhtml+xml', null, true],
            ['GET', 200, 'TEXT/HTML', 'inline', true],
            ['POST', 200, 'text/html', null, false],
            ['HEAD', 200, 'text/html', null, false],
            ['GET', 302, 'text/html', null, false],
            ['GET', 304, 'text/html', null, false],
            ['GET', 204, 'text/html', null, false],
            ['GET', 205, 'text/html', null, false],
            ['GET', 200, 'application/pdf', null, false],
            ['GET', 200, 'application/json', null, false],
            ['GET', 500, 'application/json', null, false],
            ['GET', 200, 'text/html-malformed', null, false],
            ['GET', 200, null, null, false],
            ['GET', 200, 'text/html', 'Attachment; filename="export.html"', false],
        ];
    }

    #[DataProvider('responses')]
    public function test_only_rendered_html_documents_are_pages(string $method, int $status, ?string $type, ?string $disposition, bool $expected): void
    {
        $this->assertSame($expected, Client::isHtmlPageResponse($method, $status, $type, $disposition));
    }
}
