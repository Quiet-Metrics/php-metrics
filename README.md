# laboiteacode/webanalytics-php

SDK PHP pur d'[Affluence](https://app.affluence.fr) (La Boîte à Code) : mesure d'audience sans cookie, envoyée à 100 % depuis votre serveur, donc invisible pour les adblockers. Zéro dépendance, compatible PHP >= 7.4 (mutualisés et WordPress inclus).

C'est aussi la fondation des ponts framework : [`laboiteacode/webanalytics-laravel`](../laravel) et [`laboiteacode/webanalytics-symfony`](../symfony) dépendent de ce package.

## Installation

```bash
composer require laboiteacode/webanalytics-php
```

Avant la publication sur Packagist (développement en monorepo), déclarez le package en *path repository* depuis le projet hôte :

```json
{
    "repositories": [
        { "type": "path", "url": "../WebAnalytics/packages/php", "options": { "symlink": true } }
    ]
}
```

```bash
composer require laboiteacode/webanalytics-php:@dev
```

## Configuration

Le constructeur prend la clé publique du site, la clé secrète (optionnelle mais recommandée : elle active le mode signé) et un tableau d'options :

```php
use LaBoiteACode\WebAnalytics\Client;

$wa = new Client('wa_pub_demo', 'wa_sec_xxx', [
    'endpoint' => 'https://app.affluence.fr/api/v1/collect', // défaut
    'timeout_ms' => 400,             // délai max consenti à l'envoi (min 50)
    'async' => true,                 // socket fire-and-forget ; false = cURL synchrone court
    'trust_proxy_headers' => false,  // true si l'app est derrière un reverse proxy / CDN
    'defaults' => [],                // contexte appliqué à tous les hits, ex. ['lang' => 'fr-FR']
]);
```

Les deux clés se trouvent dans les réglages du site sur le tableau de bord Affluence.

## Usage

```php
use LaBoiteACode\WebAnalytics\Client;

$wa = new Client('wa_pub_demo', 'wa_sec_xxx');

// Page vue : URL, referrer, IP et User-Agent du visiteur, langue
// sont déduits de la requête HTTP courante.
$wa->pageview();

// Événement personnalisé avec propriétés (valeurs scalaires, 30 clés max).
$wa->event('achat', ['montant' => 49, 'plan' => 'pro']);
```

Hors requête HTTP (CLI, cron, worker), passez le contexte en surcharge, `url` est obligatoire :

```php
$wa->event('import', ['lignes' => 1200], [
    'url' => 'https://app.fr/cron',
    'ip'  => $ipClient,          // IP du visiteur concerné, si connue
    'ts'  => time(),             // horodatage de l'événement
]);
```

Les surcharges acceptées sont `url`, `referrer`, `ip`, `ua`, `lang` et `ts` ; elles priment sur le contexte déduit et s'appliquent aussi à `pageview()`.

### Proxy first-party anti-adblock

[`examples/wa-proxy.php`](examples/wa-proxy.php) : un fichier unique à déposer à la racine du site client. Le navigateur ne parle qu'au domaine du site, le proxy injecte l'IP et le User-Agent réels du visiteur, signe le payload avec la clé secrète puis le transmet au serveur de collecte. Aucune liste de blocage par domaine ne peut l'attraper.

```html
<script defer src="/wa.js" data-site="wa_pub_demo" data-endpoint="/wa-proxy.php"></script>
```

Les trois constantes à renseigner (`WA_ENDPOINT`, `WA_SECRET`, `WA_MAX_BODY`) sont documentées dans l'en-tête du fichier.

## Comment ça marche

- **Payload compact** : clés courtes (`k`, `t`, `u`, `n`, `r`, `l`, `p`), plafonné à 4 Ko. Spec complète : `docs/05-api-et-sdk.md` à la racine du monorepo.
- **Mode signé** : avec la clé secrète, chaque hit part avec les en-têtes `X-WA-Timestamp` et `X-WA-Signature` (HMAC-SHA256 de `timestamp.corps`). C'est la seule chose qui autorise le serveur de collecte à honorer l'IP, le User-Agent et l'horodatage du visiteur transmis dans le payload.
- **Non bloquant** : socket « write-and-forget » (environ 1 ms perçu par la page), repli cURL avec timeout de 400 ms si les sockets sortants sont désactivés.
- **Jamais d'exception** : tout échec (endpoint injoignable, payload trop gros, contexte absent) est silencieux. L'analytics ne casse jamais le site hôte.

Compatibilité : PHP >= 7.4, extension `ext-json` uniquement (`ext-curl` suggérée pour le transport de repli). Tests : `composer test` (PHPUnit contre un vrai serveur HTTP de capture, voir `tests/`).

## Licence

MIT.
