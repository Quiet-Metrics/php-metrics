<?php

declare(strict_types=1);

namespace QuietMetrics;

/**
 * Client de collecte Quiet Metrics : tracking 100 % côté serveur,
 * sans cookie.
 *
 * Compatible PHP >= 7.4, zéro dépendance (adoption maximale : mutualisés,
 * WordPress, vieux projets). Spec du payload : docs/05-api-et-sdk.md.
 *
 * Contrat : ne JAMAIS casser le site hôte. Tout échec est silencieux,
 * l'envoi est non bloquant (socket « write-and-forget », repli cURL 400 ms).
 *
 *     $qm = new Client('qm_pub_xxx', 'qm_sec_xxx');
 *     $qm->pageview();
 *     $qm->event('achat', ['montant' => 49]);
 */
final class Client
{
    private string $publicKey;

    private ?string $secretKey;

    private string $endpoint;

    /** Délai max consenti à l'envoi (connexion comprise). */
    private int $timeoutMs;

    /** true : socket fire-and-forget ; false : cURL synchrone court. */
    private bool $async;

    /** Faire confiance à X-Forwarded-For/-Proto (app derrière un reverse proxy). */
    private bool $trustProxyHeaders;

    /** @var array<string,mixed> */
    private array $defaults;

    /**
     * @param array{endpoint?:string,timeout_ms?:int,async?:bool,trust_proxy_headers?:bool,defaults?:array} $options
     */
    public function __construct(string $publicKey, ?string $secretKey = null, array $options = [])
    {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->endpoint = $options['endpoint'] ?? 'https://quietmetrics.dev/api/v1/collect';
        $this->timeoutMs = max(50, (int) ($options['timeout_ms'] ?? 400));
        $this->async = (bool) ($options['async'] ?? true);
        $this->trustProxyHeaders = (bool) ($options['trust_proxy_headers'] ?? false);
        $this->defaults = $options['defaults'] ?? [];
    }

    /**
     * Page vue. Le contexte (URL, referrer, IP et User-Agent du visiteur,
     * langue) est déduit de la requête HTTP courante, surchargeable :
     * pageview(['url' => …, 'ip' => …]).
     *
     * @param array{url?:string,referrer?:string,ip?:string,ua?:string,lang?:string,ts?:int} $overrides
     */
    public function pageview(array $overrides = []): void
    {
        $this->send('pageview', null, [], $overrides);
    }

    /**
     * Événement personnalisé : event('inscription', ['plan' => 'pro']).
     * $props : valeurs scalaires uniquement, ≤ 30 clés (tronqué côté serveur).
     *
     * @param array<string,scalar|null> $props
     * @param array{url?:string,referrer?:string,ip?:string,ua?:string,lang?:string,ts?:int} $overrides
     */
    public function event(string $name, array $props = [], array $overrides = []): void
    {
        $this->send('event', $name, $props, $overrides);
    }

    /**
     * @param array<string,scalar|null> $props
     * @param array<string,mixed>       $overrides
     */
    private function send(string $type, ?string $name, array $props, array $overrides): void
    {
        try {
            if ($this->publicKey === '') {
                return; // clé absente (env non configuré) : aucun hit ne partirait
                        // valide, on économise la requête sur chaque page.
            }

            $ctx = array_merge($this->requestContext(), $this->defaults, $overrides);

            $payload = [
                'k' => $this->publicKey,
                't' => $type,
                // mbstring n'est pas garanti partout (zéro dépendance) ; repli
                // substr acceptable : les noms d'événements de la spec sont ASCII.
                'n' => $name !== null
                    ? (\function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120))
                    : null,
                'u' => $ctx['url'] ?? null,
                'r' => $ctx['referrer'] ?? null,
                'l' => $ctx['lang'] ?? null,
                'p' => $props !== [] ? $props : null,
                // En mode signé, l'IP/UA du VISITEUR font foi (pas ceux du
                // serveur qui exécute ce SDK) ; ignorés sans signature valide.
                'ip' => $ctx['ip'] ?? null,
                'ua' => $ctx['ua'] ?? null,
                'ts' => $ctx['ts'] ?? time(),
            ];
            $payload = array_filter($payload, static function ($v): bool {
                return $v !== null && $v !== '';
            });

            if (!isset($payload['u'])) {
                return; // rien d'exploitable (CLI sans override) : abandon silencieux
            }

            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false || strlen($body) > 4096) {
                return;
            }

            $headers = ['Content-Type: application/json'];
            if ($this->secretKey !== null) {
                $ts = (string) time();
                $headers[] = 'X-QM-Timestamp: ' . $ts;
                $headers[] = 'X-QM-Signature: ' . hash_hmac('sha256', $ts . '.' . $body, $this->secretKey);
            }

            if (!($this->async && $this->sendSocket($body, $headers))) {
                $this->sendCurl($body, $headers);
            }
        } catch (\Throwable $e) {
            // Silencieux par contrat : l'analytics ne casse jamais le site hôte.
        }
    }

    /**
     * Contexte déduit de la requête courante (vide en CLI).
     *
     * @return array<string,mixed>
     */
    private function requestContext(): array
    {
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_URI'])) {
            return [];
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        if ($this->trustProxyHeaders && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $https = $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
        }
        $host = $_SERVER['HTTP_HOST'] ?? null;
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($this->trustProxyHeaders && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                $ip = $first;
            }
        }

        $lang = null;
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $lang = substr(trim(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0]), 0, 5);
        }

        return [
            'url' => $host !== null ? sprintf('%s://%s%s', $https ? 'https' : 'http', $host, $uri) : null,
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
            'ip' => $ip,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'lang' => $lang,
        ];
    }

    /**
     * Envoi fire-and-forget : on écrit la requête HTTP dans la socket et on
     * raccroche sans lire la réponse (~1 ms perçu par la page).
     *
     * @param list<string> $headers
     */
    private function sendSocket(string $body, array $headers): bool
    {
        $parts = parse_url($this->endpoint);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }
        $tls = ($parts['scheme'] ?? 'https') === 'https';
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen(
            ($tls ? 'ssl://' : '') . $parts['host'],
            $port,
            $errno,
            $errstr,
            $this->timeoutMs / 1000
        );
        if ($fp === false) {
            return false;
        }

        $request = 'POST ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $parts['host'] . "\r\n"
            . implode("\r\n", $headers) . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;

        stream_set_timeout($fp, 0, $this->timeoutMs * 1000);
        @fwrite($fp, $request);
        @fclose($fp);

        return true;
    }

    /**
     * Repli cURL (sockets sortants désactivés chez certains mutualisés).
     *
     * @param list<string> $headers
     */
    private function sendCurl(string $body, array $headers): void
    {
        if (!\function_exists('curl_init')) {
            return;
        }
        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_NOSIGNAL => true,
        ]);
        @curl_exec($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // sans effet et déprécié depuis PHP 8 (handle objet, libéré par le GC)
        }
    }
}
