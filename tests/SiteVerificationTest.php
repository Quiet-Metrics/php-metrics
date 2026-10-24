<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuietMetrics\Client;

/**
 * Preuve de propriete du site, exigee par la plateforme avant tout crawl SEO.
 *
 * Le chemin, la forme du JSON et le contexte du HMAC forment un contrat avec
 * la plateforme : le jeton attendu est recalcule ici a la main plutot que lu
 * depuis le client, pour qu'un changement de l'un ne passe pas inapercu.
 */
final class SiteVerificationTest extends TestCase
{
    private const SECRET = 'qm_sec_test_verification';

    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function expectedDocument(string $secret = self::SECRET): string
    {
        $token = hash_hmac('sha256', 'quietmetrics-site-verification:v1', $secret);

        return '{"site_verification":["'.$token.'"]}';
    }

    private function enabledClient(): Client
    {
        return new Client('qm_pub_test', self::SECRET, ['seo_crawl' => true]);
    }

    /** @return array{0: bool, 1: string} */
    private function serve(Client $client, string $method, string $uri): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        ob_start();
        $served = $client->serveSiteVerification();
        $output = (string) ob_get_clean();

        return [$served, $output];
    }

    public function test_the_contract_constants_match_the_platform(): void
    {
        $this->assertSame('/.well-known/quietmetrics.json', Client::SITE_VERIFICATION_PATH);
        $this->assertSame('quietmetrics-site-verification:v1', Client::SITE_VERIFICATION_CONTEXT);
    }

    public function test_the_document_is_null_by_default(): void
    {
        $client = new Client('qm_pub_test', self::SECRET);

        $this->assertNull($client->siteVerificationDocument());
    }

    public function test_the_document_is_null_when_disabled_explicitly(): void
    {
        $client = new Client('qm_pub_test', self::SECRET, ['seo_crawl' => false]);

        $this->assertNull($client->siteVerificationDocument());
    }

    public function test_the_document_is_null_without_a_secret_key(): void
    {
        $this->assertNull((new Client('qm_pub_test', null, ['seo_crawl' => true]))->siteVerificationDocument());
        $this->assertNull((new Client('qm_pub_test', '', ['seo_crawl' => true]))->siteVerificationDocument());
    }

    public function test_the_document_carries_the_hmac_of_the_secret_key(): void
    {
        $document = $this->enabledClient()->siteVerificationDocument();

        $this->assertSame($this->expectedDocument(), $document);

        $decoded = json_decode((string) $document, true);
        $this->assertSame(['site_verification'], array_keys($decoded));
        $this->assertCount(1, $decoded['site_verification']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $decoded['site_verification'][0]);
    }

    public function test_the_token_does_not_depend_on_the_public_key(): void
    {
        $other = new Client('qm_pub_other', self::SECRET, ['seo_crawl' => true]);

        $this->assertSame($this->expectedDocument(), $other->siteVerificationDocument());
    }

    public function test_a_different_secret_gives_a_different_token(): void
    {
        $other = new Client('qm_pub_test', 'qm_sec_other', ['seo_crawl' => true]);

        $this->assertSame($this->expectedDocument('qm_sec_other'), $other->siteVerificationDocument());
        $this->assertNotSame($this->expectedDocument(), $other->siteVerificationDocument());
    }

    public function test_get_on_the_exact_path_serves_the_document(): void
    {
        [$served, $output] = $this->serve($this->enabledClient(), 'GET', '/.well-known/quietmetrics.json');

        $this->assertTrue($served);
        $this->assertSame($this->expectedDocument(), $output);
    }

    public function test_the_query_string_is_ignored(): void
    {
        [$served, $output] = $this->serve($this->enabledClient(), 'GET', '/.well-known/quietmetrics.json?nocache=1');

        $this->assertTrue($served);
        $this->assertSame($this->expectedDocument(), $output);
    }

    public function test_head_on_the_exact_path_is_served_without_a_body(): void
    {
        [$served, $output] = $this->serve($this->enabledClient(), 'HEAD', '/.well-known/quietmetrics.json');

        $this->assertTrue($served);
        $this->assertSame('', $output);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function requestsThatAreNotServed(): array
    {
        return [
            'post' => ['POST', '/.well-known/quietmetrics.json'],
            'put' => ['PUT', '/.well-known/quietmetrics.json'],
            'options' => ['OPTIONS', '/.well-known/quietmetrics.json'],
            'root' => ['GET', '/'],
            'other page' => ['GET', '/pricing'],
            'trailing slash' => ['GET', '/.well-known/quietmetrics.json/'],
            'suffix' => ['GET', '/.well-known/quietmetrics.jsonx'],
            'nested' => ['GET', '/blog/.well-known/quietmetrics.json'],
            'other well-known file' => ['GET', '/.well-known/security.txt'],
            'case' => ['GET', '/.well-known/QuietMetrics.json'],
        ];
    }

    #[DataProvider('requestsThatAreNotServed')]
    public function test_any_other_request_is_left_alone(string $method, string $uri): void
    {
        [$served, $output] = $this->serve($this->enabledClient(), $method, $uri);

        $this->assertFalse($served);
        $this->assertSame('', $output);
    }

    public function test_nothing_is_served_when_the_option_is_off(): void
    {
        [$served, $output] = $this->serve(new Client('qm_pub_test', self::SECRET), 'GET', '/.well-known/quietmetrics.json');

        $this->assertFalse($served);
        $this->assertSame('', $output);
    }

    public function test_nothing_is_served_without_a_secret_key(): void
    {
        [$served, $output] = $this->serve(new Client('qm_pub_test', null, ['seo_crawl' => true]), 'GET', '/.well-known/quietmetrics.json');

        $this->assertFalse($served);
        $this->assertSame('', $output);
    }

    public function test_nothing_is_served_outside_an_http_request(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        ob_start();
        $served = $this->enabledClient()->serveSiteVerification();
        $output = (string) ob_get_clean();

        $this->assertFalse($served);
        $this->assertSame('', $output);
    }
}
