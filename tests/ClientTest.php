<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

use QuietMetrics\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private static CaptureServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new CaptureServer();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        self::$server->reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function client(?string $secret = null, array $options = []): Client
    {
        return new Client('qm_pub_test', $secret, $options + [
            'endpoint' => self::$server->endpoint(),
            'async' => false, // cURL synchrone : capture déterministe en test
        ]);
    }

    /** Simule une requête HTTP entrante sur le site du client. */
    private function fakeRequest(): void
    {
        $_SERVER['HTTP_HOST'] = 'monsite.fr';
        $_SERVER['REQUEST_URI'] = '/tarifs?utm_source=nl';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'NavigateurTest/1.0';
        $_SERVER['HTTP_REFERER'] = 'https://google.fr/';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9';
    }

    public function test_pageview_construit_le_payload_depuis_la_requete_courante(): void
    {
        $this->fakeRequest();

        $this->client()->pageview();

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);
        $payload = json_decode($requests[0]['body'], true);

        $this->assertSame('qm_pub_test', $payload['k']);
        $this->assertSame('pageview', $payload['t']);
        $this->assertSame('https://monsite.fr/tarifs?utm_source=nl', $payload['u']);
        $this->assertSame('https://google.fr/', $payload['r']);
        $this->assertSame('203.0.113.9', $payload['ip']);
        $this->assertSame('NavigateurTest/1.0', $payload['ua']);
        $this->assertSame('fr-FR', $payload['l']);
        $this->assertIsInt($payload['ts']);
        $this->assertSame('POST', $requests[0]['method']);
    }

    public function test_le_mode_signe_produit_une_signature_hmac_valide(): void
    {
        $this->fakeRequest();

        $this->client('qm_sec_secret')->event('achat', ['montant' => 49]);

        $request = self::$server->requests()[0];
        $timestamp = $request['headers']['x-qm-timestamp'];
        $signature = $request['headers']['x-qm-signature'];

        $this->assertNotEmpty($timestamp);
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$request['body'], 'qm_sec_secret'),
            $signature,
            'la signature couvre exactement "timestamp.corps"',
        );

        $payload = json_decode($request['body'], true);
        $this->assertSame('event', $payload['t']);
        $this->assertSame('achat', $payload['n']);
        $this->assertSame(['montant' => 49], $payload['p']);
    }

    public function test_sans_secret_aucun_en_tete_de_signature(): void
    {
        $this->fakeRequest();

        $this->client()->pageview();

        $headers = self::$server->requests()[0]['headers'];
        $this->assertArrayNotHasKey('x-qm-signature', $headers);
        $this->assertArrayNotHasKey('x-qm-timestamp', $headers);
    }

    public function test_les_surcharges_priment_sur_le_contexte(): void
    {
        $this->fakeRequest();

        $this->client()->event('import', [], [
            'url' => 'https://app.fr/cron',
            'ip' => '198.51.100.7',
            'ts' => 1_700_000_000,
        ]);

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame('https://app.fr/cron', $payload['u']);
        $this->assertSame('198.51.100.7', $payload['ip']);
        $this->assertSame(1_700_000_000, $payload['ts']);
    }

    public function test_en_cli_sans_url_rien_ne_part(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);

        $this->client()->pageview(); // aucun contexte ni surcharge → abandon silencieux

        $this->assertSame([], self::$server->requests(1, 300));
    }

    public function test_le_nom_d_evenement_est_tronque(): void
    {
        $this->fakeRequest();

        $this->client()->event(str_repeat('a', 200));

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame(120, mb_strlen($payload['n']));
    }

    public function test_endpoint_injoignable_echoue_en_silence(): void
    {
        $this->fakeRequest();

        $client = new Client('qm_pub_test', null, [
            'endpoint' => 'http://127.0.0.1:1/api/v1/collect', // port fermé
            'async' => false,
            'timeout_ms' => 100,
        ]);

        $client->pageview();
        $client->event('x');

        $this->assertTrue(true, 'aucune exception : l\'analytics ne casse jamais le site hôte');
    }

    public function test_transport_fire_and_forget_socket(): void
    {
        $this->fakeRequest();

        // async = true (défaut) : socket « write-and-forget ».
        $client = new Client('qm_pub_test', 'qm_sec_secret', ['endpoint' => self::$server->endpoint()]);
        $client->pageview();

        $requests = self::$server->requests(1, 3000);
        $this->assertCount(1, $requests, 'le hit arrive même sans lire la réponse');
        $this->assertSame('qm_pub_test', json_decode($requests[0]['body'], true)['k']);
        $this->assertArrayHasKey('x-qm-signature', $requests[0]['headers']);
    }

    public function test_cle_publique_vide_aucun_envoi(): void
    {
        // Env non configuré (pont Laravel sans QUIET_METRICS_PUBLIC_KEY, par
        // exemple) : aucun hit valide ne pourrait partir, on économise la
        // requête sur chaque page plutôt que d'arroser le serveur de 400.
        $this->fakeRequest();

        $client = new Client('', 'qm_sec_secret', [
            'endpoint' => self::$server->endpoint(),
            'async' => false,
        ]);
        $client->pageview();
        $client->event('achat', ['montant' => 49]);

        $this->assertSame([], self::$server->requests(1, 400), 'clé vide : rien ne part');
    }
}
