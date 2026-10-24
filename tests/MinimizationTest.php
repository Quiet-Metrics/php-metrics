<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuietMetrics\Client;

/**
 * Ce qui quitte le site mesuré : seulement ce que la plateforme lit.
 *
 * La plateforme ne garde que le chemin, quatre paramètres de campagne et
 * l'hôte du référent. Tout le reste transitait pour rien, jusqu'aux champs
 * d'un formulaire envoyé en GET (nom, e-mail, message).
 */
final class MinimizationTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function urls(): array
    {
        return [
            'formulaire en GET' => ['https://site.fr/me-contacter?name=Jean&email=jean%40exemple.fr&message=Bonjour', 'https://site.fr/me-contacter'],
            'campagne conservée, ordre et encodage intacts' => ['https://site.fr/p?email=a%40b.fr&utm_source=news%20letter&x=1&ref=hn', 'https://site.fr/p?utm_source=news%20letter&ref=hn'],
            'les quatre paramètres' => ['https://site.fr/?utm_campaign=c&utm_medium=m&utm_source=s&ref=r', 'https://site.fr/?utm_campaign=c&utm_medium=m&utm_source=s&ref=r'],
            'clé encodée reconnue' => ['https://site.fr/p?utm%5Fsource=s', 'https://site.fr/p?utm%5Fsource=s'],
            'voisins de nom écartés' => ['https://site.fr/p?referrer=x&utm_source_platform=y&utm_term=t&utm_content=c', 'https://site.fr/p'],
            'tableau écarté' => ['https://site.fr/p?utm_source[]=x', 'https://site.fr/p'],
            'port conservé' => ['http://localhost:8000/p?token=abc', 'http://localhost:8000/p'],
            'identifiants de connexion retirés' => ['https://user:secret@site.fr/p', 'https://site.fr/p'],
            'fragment retiré' => ['https://site.fr/p#access_token=abc', 'https://site.fr/p'],
            'sans chemin' => ['https://site.fr?utm_source=s', 'https://site.fr/?utm_source=s'],
            'paramètre sans valeur' => ['https://site.fr/p?ref&debug', 'https://site.fr/p?ref'],
            'sans hôte' => ['/p?email=a%40b.fr', null],
            'illisible' => ['http:///p', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_l_url_ne_garde_que_ce_que_la_plateforme_lit(string $url, ?string $expected): void
    {
        $this->assertSame($expected, Client::minimizeUrl($url));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function referrers(): array
    {
        return [
            'chemin et requête retirés' => ['https://site.fr/me-contacter?email=a%40b.fr', 'https://site.fr/'],
            'moteur de recherche' => ['https://www.google.fr/search?q=mon+nom', 'https://www.google.fr/'],
            'port conservé' => ['http://localhost:8000/p', 'http://localhost:8000/'],
            'application Android' => ['android-app://com.google.android.gm/', 'android-app://com.google.android.gm/'],
            'vide' => ['', null],
            'absent' => [null, null],
            'sans hôte' => ['/relatif', null],
        ];
    }

    #[DataProvider('referrers')]
    public function test_le_referent_se_reduit_a_son_origine(?string $referrer, ?string $expected): void
    {
        $this->assertSame($expected, Client::minimizeReferrer($referrer));
    }

    public function test_la_liste_est_celle_que_lit_la_plateforme(): void
    {
        $this->assertSame(['utm_source', 'utm_medium', 'utm_campaign', 'ref'], Client::FORWARDED_QUERY_PARAMS);
    }
}
