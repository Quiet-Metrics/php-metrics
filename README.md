# laboiteacode/webanalytics-php — package cœur (PHP pur)

Le cœur du tracking **100 % côté serveur, sans cookie et imblocable par les adblockers** : transport non bloquant, signature HMAC, contexte de requête. Zéro dépendance, PHP ≥ 7.4 (mutualisés et WordPress inclus).

C'est à la fois **le produit « PHP pur »** (installable seul sur n'importe quel projet PHP) et **la fondation des ponts framework** :

| Projet du client | Package à installer |
|---|---|
| PHP pur / autre framework | `laboiteacode/webanalytics-php` (celui-ci) |
| Laravel | [`laboiteacode/webanalytics-laravel`](../laravel) (dépend de celui-ci) |
| Symfony | [`laboiteacode/webanalytics-symfony`](../symfony) (dépend de celui-ci) |
| WordPress | plugin officiel v1.x (embarque celui-ci) |

Développés dans ce monorepo ; split en lecture seule vers des dépôts publics + Packagist (MIT) au lancement.

## Usage

```php
use LaBoiteACode\WebAnalytics\Client;

$wa = new Client('wa_pub_xxx', 'wa_sec_xxx', [
    'endpoint' => 'https://collect.example.fr/api/v1/collect',
    // 'trust_proxy_headers' => true,   // app derrière un reverse proxy / CDN
]);

$wa->pageview();                          // contexte déduit de la requête courante
$wa->event('achat', ['montant' => 49]);   // événement + propriétés scalaires
$wa->event('import', [], ['url' => 'https://app.fr/cron', 'ip' => $ipClient]); // hors requête HTTP (CLI/job)
```

- **Non bloquant** : socket « write-and-forget » (~1 ms perçu), repli cURL 400 ms.
- **Jamais d'exception** : tout échec est silencieux — l'analytics ne casse pas le site hôte.
- **Mode signé** : avec la clé secrète, chaque hit est signé (HMAC-SHA256) ; c'est ce qui autorise le serveur de collecte à honorer l'IP/UA du *visiteur* transmis dans le payload (spec : `docs/05-api-et-sdk.md`).

## Proxy first-party anti-adblock

[`examples/wa-proxy.php`](examples/wa-proxy.php) : un fichier à déposer à la racine du site client — le tracking JS devient entièrement first-party (script servi et collecte effectuée sous le domaine du client), invisible pour les listes de blocage. Instructions dans l'en-tête du fichier.

## Tests

```bash
composer update && composer test
```

8 tests contre un **vrai serveur HTTP de capture** (`tests/CaptureServer.php` : `php -S` + journal JSON-lines) : contexte déduit de la requête, signature HMAC vérifiée octet par octet, overrides, garde CLI, troncatures, échec silencieux endpoint fermé, transport socket asynchrone réel. Le CaptureServer est réutilisé par les suites des ponts Laravel et Symfony.

Compatibilité vérifiée en CI : PHP 7.4 (PHPUnit 9) → PHP 8.4 (PHPUnit 12).

## Installer en local (avant la publication Packagist)

Depuis un projet client sur la même machine, déclarez le package en *path repository* :

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

Le symlink fait que toute modification du package est visible immédiatement dans le projet hôte.

## Reste à faire avant v1

- [x] Tests : contexte, signature, troncatures, CLI, transport réel (PHPUnit + CaptureServer).
- [ ] Middleware PSR-15 générique (dans ce package ou pont dédié, à trancher).
- [ ] Helper de batch pour les gros imports (`$wa->flush()`).
- [ ] Split monorepo + publication Packagist au lancement (CI monorepo : ✅).
