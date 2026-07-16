# quiet-metrics/php-metrics

SDK PHP pur de [Quiet Metrics](https://app.quietmetrics.dev) (La Boîte à Code) : mesure d'audience sans cookie, envoyée à 100 % depuis votre serveur, donc invisible pour les adblockers. Zéro dépendance, compatible PHP >= 7.4 (mutualisés et WordPress inclus).

C'est aussi la fondation des ponts framework : [`quiet-metrics/laravel-metrics`](../laravel) et [`quiet-metrics/symfony-metrics`](../symfony) dépendent de ce package.

## Installation

```bash
composer require quiet-metrics/php-metrics
```

Avant la publication sur Packagist (bêta privée), installez depuis le dépôt GitHub (accès requis) :

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Quiet-Metrics/php-metrics" }
    ]
}
```

```bash
composer require quiet-metrics/php-metrics:@dev
```

## Configuration

Le constructeur prend la clé publique du site, la clé secrète (optionnelle mais recommandée : elle active le mode signé) et un tableau d'options :

```php
use QuietMetrics\Client;

$qm = new Client('qm_pub_demo', 'qm_sec_xxx', [
    'endpoint' => 'https://app.quietmetrics.dev/api/v1/collect', // défaut
    'timeout_ms' => 400,             // délai max consenti à l'envoi (min 50)
    'async' => true,                 // socket fire-and-forget ; false = cURL synchrone court
    'trust_proxy_headers' => false,  // true si l'app est derrière un reverse proxy / CDN
    'defaults' => [],                // contexte appliqué à tous les hits, ex. ['lang' => 'fr-FR']
]);
```

Les deux clés se trouvent dans les réglages du site sur le tableau de bord Quiet Metrics.

## Usage

```php
use QuietMetrics\Client;

$qm = new Client('qm_pub_demo', 'qm_sec_xxx');

// Page vue : URL, referrer, IP et User-Agent du visiteur, langue
// sont déduits de la requête HTTP courante.
$qm->pageview();

// Événement personnalisé avec propriétés (valeurs scalaires, 30 clés max).
$qm->event('achat', ['montant' => 49, 'plan' => 'pro']);
```

Hors requête HTTP (CLI, cron, worker), passez le contexte en surcharge, `url` est obligatoire :

```php
$qm->event('import', ['lignes' => 1200], [
    'url' => 'https://app.fr/cron',
    'ip'  => $ipClient,          // IP du visiteur concerné, si connue
    'ts'  => time(),             // horodatage de l'événement
]);
```

Les surcharges acceptées sont `url`, `referrer`, `ip`, `ua`, `lang` et `ts` ; elles priment sur le contexte déduit et s'appliquent aussi à `pageview()`.

### Proxy first-party anti-adblock

[`examples/qm-proxy.php`](examples/qm-proxy.php) : un fichier unique à déposer à la racine du site client. Le navigateur ne parle qu'au domaine du site, le proxy injecte l'IP et le User-Agent réels du visiteur, signe le payload avec la clé secrète puis le transmet au serveur de collecte. Aucune liste de blocage par domaine ne peut l'attraper.

```html
<script defer src="/qm.js" data-site="qm_pub_demo" data-endpoint="/qm-proxy.php"></script>
```

Les trois constantes à renseigner (`QM_ENDPOINT`, `QM_SECRET`, `QM_MAX_BODY`) sont documentées dans l'en-tête du fichier.

## Comment ça marche

- **Payload compact** : clés courtes (`k`, `t`, `u`, `n`, `r`, `l`, `p`), plafonné à 4 Ko. Spec complète : `docs/05-api-et-sdk.md` à la racine du monorepo.
- **Mode signé** : avec la clé secrète, chaque hit part avec les en-têtes `X-QM-Timestamp` et `X-QM-Signature` (HMAC-SHA256 de `timestamp.corps`). C'est la seule chose qui autorise le serveur de collecte à honorer l'IP, le User-Agent et l'horodatage du visiteur transmis dans le payload.
- **Non bloquant** : socket « write-and-forget » (environ 1 ms perçu par la page), repli cURL avec timeout de 400 ms si les sockets sortants sont désactivés.
- **Jamais d'exception** : tout échec (endpoint injoignable, payload trop gros, contexte absent) est silencieux. L'analytics ne casse jamais le site hôte.

Compatibilité : PHP >= 7.4, extension `ext-json` uniquement (`ext-curl` suggérée pour le transport de repli). Tests : `composer test` (PHPUnit contre un vrai serveur HTTP de capture, voir `tests/`).

## Licence

MIT.
