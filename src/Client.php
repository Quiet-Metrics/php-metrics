<?php

declare(strict_types=1);

namespace QuietMetrics;

/**
 * Client de collecte Quiet Metrics : tracking 100 % côté serveur,
 * sans cookie d'identification ni de traçabilité.
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
    /**
     * Marqueur d'exclusion, sous ce nom comme cookie et comme paramètre d'URL.
     *
     * Le SEUL écrit de Quiet Metrics chez le visiteur, et il sert à NE PAS
     * compter. Voir isOptedOut() pour ce qui le sépare d'un cookie
     * d'identification ou de traçabilité.
     */
    public const OPT_OUT_MARKER = 'qm_ignore';

    /**
     * Durée de vie du marqueur : cinq ans, en secondes.
     *
     * La même que le max-age posé par le traceur JS
     * (packages/tracker-js/tracker.js) : un refus ne doit pas expirer d'un
     * côté avant l'autre selon le mode de suivi du site.
     */
    public const OPT_OUT_LIFETIME = 157680000;

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
     * Le client annonce-t-il un préchargement plutôt qu'une visite ?
     *
     * Quand le visiteur tape une adresse, Chrome charge fréquemment la page à
     * l'avance : une vraie requête GET qui renvoie un vrai 200, mais qu'aucun
     * humain ne voit tant que la navigation n'est pas confirmée. Mesurée côté
     * serveur elle fabriquait une page vue, parfois un visiteur, et restait
     * invisible pour un traceur JS puisqu'une page préchargée n'exécute pas
     * ses scripts avant activation. C'est une source d'écart entre les deux
     * méthodes, et elle penche toujours du même côté.
     *
     * Trois en-têtes couvrent le parc : `Sec-Purpose` (forme actuelle,
     * `prefetch` ou `prefetch;prerender`), `Purpose` (Chrome plus ancien) et
     * `X-Moz` (Firefox).
     *
     * La VALEUR est lue, et pas la seule présence de l'en-tête : `Sec-Purpose`
     * est un en-tête structuré dont la spécification prévoit d'autres jetons,
     * et se contenter de sa présence ferait disparaître des visites réelles au
     * premier que le navigateur ajoutera.
     *
     * Publique et statique parce que les intégrations Laravel et Symfony
     * doivent poser la même question SANS passer par les superglobales : sous
     * Octane, RoadRunner ou FrankenPHP, `$_SERVER` peut appartenir à une
     * requête précédente.
     */
    public static function announcesPrefetch(?string ...$headerValues): bool
    {
        foreach ($headerValues as $value) {
            if ($value === null) {
                continue;
            }
            // `strpos` et non `str_contains` : ce fichier tient un contrat
            // « PHP >= 7.4 » (mutualisés, WordPress, vieux projets) et
            // `str_contains` est arrivé en 8.0. L'écart aurait été MUET :
            // `send()` avale tout dans un `catch (\Throwable)`, donc un
            // « Call to undefined function » y aurait simplement arrêté toute
            // émission, sans erreur visible nulle part.
            $value = strtolower($value);
            if (strpos($value, 'prefetch') !== false || strpos($value, 'prerender') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * La personne a-t-elle posé le marqueur d'exclusion ?
     *
     * `qm_ignore=1` est le marqueur de refus : la personne le pose elle-même
     * en visitant n'importe quelle URL du site avec `?qm_ignore=1`, et le
     * retire avec `?qm_ignore=0`. Il ne contient AUCUN identifiant, n'est
     * JAMAIS transmis à Quiet Metrics, et n'existe que pour ARRÊTER la mesure.
     * C'est ce qui le sépare d'un cookie d'identification ou de traçabilité,
     * et ce qui l'exempte de consentement.
     *
     * Seule la valeur `1` exclut, comme la lit le traceur JS. Se contenter de
     * la PRÉSENCE du cookie ferait disparaître des visites réelles :
     * `qm_ignore=0` est justement ce qu'écrit un retrait, et un cookie vidé
     * par un intermédiaire n'est pas un refus.
     *
     * Publique et statique parce que les intégrations Laravel et Symfony
     * doivent poser la même question SANS passer par les superglobales : sous
     * Octane, RoadRunner ou FrankenPHP, `$_COOKIE` peut appartenir à une
     * requête précédente.
     */
    public static function isOptedOut(?string $cookieValue): bool
    {
        return $cookieValue === '1';
    }

    /**
     * Que demande l'URL au sujet du marqueur d'exclusion ?
     *
     * Toute valeur autre que `1` et `0` ne dit rien plutôt que de valoir un
     * retrait : un `?qm_ignore=` tronqué par un partage de lien ne doit pas
     * remettre dans la mesure quelqu'un qui en était sorti.
     *
     * @param string|null $queryValue valeur du paramètre `qm_ignore` de l'URL
     *
     * @return bool|null true : poser le marqueur ; false : le retirer ;
     *                   null : l'URL ne dit rien, un refus déjà posé reste intact
     */
    public static function optOutSignal(?string $queryValue): ?bool
    {
        if ($queryValue === '1') {
            return true;
        }

        if ($queryValue === '0') {
            return false;
        }

        return null;
    }

    /**
     * Pose ou retire le marqueur d'exclusion demandé par l'URL courante,
     * en lisant `$_GET` et en écrivant un cookie propriétaire.
     *
     * À appeler TÔT dans la requête, AVANT tout envoi de sortie : poser un
     * cookie écrit un en-tête HTTP, et il est trop tard une fois le corps de
     * la page commencé. Si la sortie a déjà commencé, l'en-tête est abandonné
     * en silence plutôt que de faire apparaître un avertissement PHP dans la
     * page hôte (contrat : ne JAMAIS casser le site hôte).
     *
     * `$_COOKIE` est mis à jour dans le processus en plus de l'en-tête, pour
     * qu'un isOptedOut() ultérieur dans la MÊME requête voie la décision : la
     * page qui pose le refus ne se compte donc pas elle-même, exactement comme
     * le traceur JS qui relit son propre cookie avant de décider.
     *
     * Réservée aux intégrations sans objet Request (WordPress, PHP nu) :
     * Laravel et Symfony traitent le même signal sur leur Request et leur
     * Response, là où `setcookie()` n'écrirait pas dans la bonne réponse sous
     * un serveur à processus persistants.
     */
    public static function handleOptOutRequest(): void
    {
        $queryValue = isset($_GET[self::OPT_OUT_MARKER]) && \is_string($_GET[self::OPT_OUT_MARKER])
            ? $_GET[self::OPT_OUT_MARKER]
            : null;

        $signal = self::optOutSignal($queryValue);
        if ($signal === null) {
            return;
        }

        if (!headers_sent()) {
            // `secure` d'après $_SERVER['HTTPS'] seul : X-Forwarded-Proto n'est
            // pas lu ici, faute d'instance pour dire si le proxy est de
            // confiance. Un en-tête falsifié poserait un cookie Secure qu'un
            // site en http ne pourrait pas stocker, et le refus serait
            // silencieusement perdu ; l'oublier ne coûte qu'un cran de rigueur.
            $https = $_SERVER['HTTPS'] ?? '';

            setcookie(self::OPT_OUT_MARKER, $signal ? '1' : '', [
                'expires' => $signal ? time() + self::OPT_OUT_LIFETIME : 1,
                'path' => '/',
                'secure' => $https !== '' && $https !== 'off',
                // Lisible par le traceur JS à dessein : c'est le même marqueur
                // pour les deux modes de suivi, une seule visite doit couvrir
                // le mode script comme le mode serveur.
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        if ($signal) {
            $_COOKIE[self::OPT_OUT_MARKER] = '1';
        } else {
            unset($_COOKIE[self::OPT_OUT_MARKER]);
        }
    }

    /**
     * Contexte déduit de la requête courante (vide en CLI, vide aussi quand la
     * requête est un préchargement ou vient d'une personne qui a posé le
     * marqueur d'exclusion : dans ces cas il n'y a pas de visite à rapporter,
     * et `send()` abandonne faute d'URL exploitable).
     *
     * @return array<string,mixed>
     */
    private function requestContext(): array
    {
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_URI'])) {
            return [];
        }

        if (self::announcesPrefetch(
            $_SERVER['HTTP_SEC_PURPOSE'] ?? null,
            $_SERVER['HTTP_PURPOSE'] ?? null,
            $_SERVER['HTTP_X_MOZ'] ?? null,
        )) {
            return [];
        }

        // Le refus de la personne, lu sur son cookie : la mesure s'arrête ici.
        $marker = $_COOKIE[self::OPT_OUT_MARKER] ?? null;
        if (self::isOptedOut(\is_string($marker) ? $marker : null)) {
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
