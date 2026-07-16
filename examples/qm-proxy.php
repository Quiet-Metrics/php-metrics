<?php

/**
 * Quiet Metrics, proxy first-party anti-adblock : fichier unique à
 * déposer à la racine du site client (aucune dépendance, PHP >= 7.4).
 *
 * Le navigateur n'appelle QUE le domaine du site : les listes de blocage par
 * domaine (EasyPrivacy…) ne voient jamais notre endpoint. Le proxy injecte
 * l'IP et le User-Agent réels du visiteur dans le payload puis le signe avec
 * la clé secrète (mode signé, docs/05-api-et-sdk.md §1), avant de le
 * transmettre au serveur de collecte.
 *
 * Installation :
 *   1. Déposer ce fichier (et une copie de qm.js) à la racine du site.
 *   2. Renseigner les 3 constantes ci-dessous.
 *   3. Snippet :
 *      <script defer src="/qm.js" data-site="qm_pub_…" data-endpoint="/qm-proxy.php"></script>
 */

const QM_ENDPOINT = 'https://app.quietmetrics.dev/api/v1/collect';
const QM_SECRET   = '';   // qm_sec_… (recommandé : active le mode signé)
const QM_MAX_BODY = 4096; // octets

// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, QM_MAX_BODY + 1);
if ($raw === false || $raw === '' || strlen($raw) > QM_MAX_BODY) {
    http_response_code(400);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// Contexte réel du visiteur, injecté côté serveur (le navigateur ne connaît
// pas son IP publique). Sans signature, le serveur de collecte les ignorera
// et se rabattra sur l'IP du proxy : d'où l'intérêt de QM_SECRET.
$payload['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
$payload['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
$payload['ts'] = time();
if (!isset($payload['l']) && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $payload['l'] = substr(trim(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0]), 0, 5);
}

$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($body === false) {
    http_response_code(400);
    exit;
}

$headers = ['Content-Type: application/json'];
if (QM_SECRET !== '') {
    $ts = (string) time();
    $headers[] = 'X-QM-Timestamp: ' . $ts;
    $headers[] = 'X-QM-Signature: ' . hash_hmac('sha256', $ts . '.' . $body, QM_SECRET);
}

// Transmission (cURL, timeout court). Toujours répondre 202 au navigateur :
// une panne d'analytics ne doit jamais se voir sur le site.
if (function_exists('curl_init') && ($ch = curl_init(QM_ENDPOINT)) !== false) {
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_TIMEOUT_MS => 800,
        CURLOPT_NOSIGNAL => true,
    ]);
    @curl_exec($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch); // sans effet et déprécié depuis PHP 8 (handle objet, libéré par le GC)
    }
}

http_response_code(202);
header('Content-Type: application/json');
echo '{}';
