# quiet-metrics/php-metrics

![Quiet Metrics : PHP SDK](art/banner.png)

> 🇬🇧 [English version](README.md)

SDK PHP pur de [Quiet Metrics](https://quietmetrics.dev) (La Boîte à Code) : mesure d'audience sans cookie d'identification ni de traçabilité, envoyée à 100 % depuis votre serveur, donc invisible pour les adblockers. Zéro dépendance, compatible PHP >= 7.4 (mutualisés et WordPress inclus).

C'est aussi la fondation des ponts framework : [`quiet-metrics/laravel-metrics`](https://github.com/Quiet-Metrics/laravel-metrics) et [`quiet-metrics/symfony-metrics`](https://github.com/Quiet-Metrics/symfony-metrics) dépendent de ce package.

## Installation

```bash
composer require quiet-metrics/php-metrics
```

## Configuration

Le constructeur prend la clé publique du site, la clé secrète et un tableau d'options.

> **La clé secrète est indispensable en envoi serveur.** Elle active le mode
> signé (HMAC), seul cas où la plateforme fait confiance à l'IP et au
> User-Agent du visiteur transmis dans le payload. Sans elle, chaque hit est
> attribué à l'adresse IP de VOTRE serveur : tous vos visiteurs n'en
> compteraient qu'un seul. Ne l'omettez que derrière le proxy first-party
> (`examples/qm-proxy.php`), qui signe lui-même.

```php
use QuietMetrics\Client;

$qm = new Client('qm_pub_demo', 'qm_sec_xxx', [
    'endpoint' => 'https://quietmetrics.dev/api/v1/collect', // défaut
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

## S'exclure de la mesure

Un visiteur peut demander à ne plus être compté, sans compte et sans écrire à personne : il visite une page de votre site avec `?qm_ignore=1`, et `?qm_ignore=0` le remet dans la mesure.

```
https://monsite.fr/?qm_ignore=1     ne plus être compté
https://monsite.fr/?qm_ignore=0     être compté à nouveau
```

Le marqueur est un **cookie propriétaire de votre site**, nommé `qm_ignore` et valant `1` (`path=/`, `samesite=lax`, `secure` en https, cinq ans). La lecture est automatique : tant que le marqueur est là, `pageview()` et `event()` n'envoient rien. L'écriture, elle, se branche en une ligne, à appeler tôt dans la requête et avant tout envoi de sortie, poser ou retirer un cookie écrivant un en-tête HTTP :

```php
\QuietMetrics\Client::handleOptOutRequest();
```

L'appel est statique, sans effet quand l'URL ne demande rien, et silencieux si les en-têtes sont déjà partis : conformément au contrat du package, il ne casse jamais le site hôte.

Il ne contient aucun identifiant (sa valeur est la même chez tout le monde), il n'est jamais transmis à Quiet Metrics, et il n'existe que pour arrêter la mesure : c'est un marqueur de refus, pas un traceur. Le tracker JS écrit en plus la même valeur en `localStorage`, mais un SDK serveur ne lit que le cookie : une seule visite suffit donc pour les deux modes de suivi.

## Continuité de visite

Quand l'empreinte visiteur change en cours de visite (4G puis wifi), la même personne compterait sinon pour deux visiteurs uniques le même jour. Un second **cookie propriétaire de votre site** ferme cet écart : `qm_visit`, valant `1` (`path=/`, `samesite=lax`, `secure` en https), sur une fenêtre glissante de dix minutes repoussée à chaque hit mesuré. Chaque hit reporte dans la clé `c` du payload s'il était déjà là.

Sa valeur est constante, la même chez tout le monde : elle n'identifie personne, elle dit seulement qu'une visite est déjà en cours sur ce navigateur. Il n'est jamais écrit chez quelqu'un qui a posé le marqueur d'exclusion, ni quand rien n'est mesuré.

Sa lecture est automatique. L'ouverture de la fenêtre tient en une ligne, à n'appeler que pour un hit mesuré, tôt dans la requête et avant tout envoi de sortie ; elle rend l'état d'avant, à transmettre plutôt qu'à relire sur le cookie qu'on vient de rafraîchir :

```php
$enCours = \QuietMetrics\Client::handleVisitRequest();
$qm->pageview(['visit' => $enCours]);
```

À savoir si votre site est mis en cache : une réponse mesurée porte désormais un en-tête `Set-Cookie`, que certains reverse proxys et CDN prennent comme une raison de ne pas stocker la réponse.

## Comment ça marche

- **Payload compact** : clés courtes (`k`, `t`, `u`, `n`, `r`, `l`, `p`, `c`), plafonné à 4 Ko. Spec complète : `docs/05-api-et-sdk.md` à la racine du monorepo.
- **Mode signé** : avec la clé secrète, chaque hit part avec les en-têtes `X-QM-Timestamp` et `X-QM-Signature` (HMAC-SHA256 de `timestamp.corps`). C'est la seule chose qui autorise le serveur de collecte à honorer l'IP, le User-Agent et l'horodatage du visiteur transmis dans le payload.
- **Non bloquant** : socket « write-and-forget » (environ 1 ms perçu par la page), repli cURL avec timeout de 400 ms si les sockets sortants sont désactivés.
- **Jamais d'exception** : tout échec (endpoint injoignable, payload trop gros, contexte absent) est silencieux. L'analytics ne casse jamais le site hôte.

Compatibilité : PHP >= 7.4, extension `ext-json` uniquement (`ext-curl` suggérée pour le transport de repli). Tests : `composer test` (PHPUnit contre un vrai serveur HTTP de capture, voir `tests/`).

## Licence

MIT. Un produit [La Boîte à Code](https://laboiteacode.fr) pour [Quiet Metrics](https://quietmetrics.dev).
