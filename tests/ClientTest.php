<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

use QuietMetrics\Client;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @var array<string, mixed> */
    private array $cookieBackup;

    /** @var array<string, mixed> */
    private array $getBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        // Le marqueur d'exclusion se lit dans $_COOKIE et se pose depuis $_GET :
        // ces deux superglobales sont restaurees pour qu'un test n'herite pas du
        // refus pose par son voisin.
        $this->cookieBackup = $_COOKIE;
        $this->getBackup = $_GET;
        self::$server->reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_COOKIE = $this->cookieBackup;
        $_GET = $this->getBackup;
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

    /**
     * En-têtes par lesquels un navigateur annonce qu'il précharge.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function annoncesDePrechargement(): array
    {
        return [
            'Chrome prerender' => ['HTTP_SEC_PURPOSE', 'prefetch;prerender'],
            'Chrome prefetch' => ['HTTP_SEC_PURPOSE', 'prefetch'],
            'Chrome, forme ancienne' => ['HTTP_PURPOSE', 'prefetch'],
            'Firefox' => ['HTTP_X_MOZ', 'prefetch'],
            'casse indifférente' => ['HTTP_SEC_PURPOSE', 'Prefetch;Prerender'],
        ];
    }

    /**
     * Un préchargement n'est pas une visite.
     *
     * Quand le visiteur tape une adresse, Chrome charge souvent la page à
     * l'avance : une vraie requête GET, un vrai 200, mais aucun humain ne la
     * voit tant que la navigation n'est pas confirmée. Comptée serveur, elle
     * fabriquait une page vue et parfois un visiteur, invisibles pour un
     * traceur JS puisque la page préchargée n'est pas activée. Le navigateur
     * l'annonce, il suffisait de le lire.
     */
    #[DataProvider('annoncesDePrechargement')]
    public function test_un_prechargement_annonce_n_envoie_aucune_page_vue(string $entete, string $valeur): void
    {
        $this->fakeRequest();
        $_SERVER[$entete] = $valeur;

        $this->client()->pageview();

        $this->assertSame([], self::$server->requests(), $entete.': '.$valeur.' annonce un préchargement, pas une visite');
    }

    /** Un événement serveur émis pendant un préchargement n'a pas plus eu lieu. */
    public function test_un_prechargement_annonce_n_envoie_aucun_evenement(): void
    {
        $this->fakeRequest();
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';

        $this->client()->event('achat');

        $this->assertSame([], self::$server->requests());
    }

    /**
     * Le filtre lit la VALEUR, pas la simple présence de l'en-tête.
     *
     * `Sec-Purpose` est un en-tête structuré dont la spécification prévoit
     * d'autres jetons : traiter sa présence comme un préchargement ferait
     * disparaître des visites réelles au premier ajout du navigateur.
     */
    public function test_un_sec_purpose_qui_n_annonce_pas_de_prechargement_laisse_partir_la_page_vue(): void
    {
        $this->fakeRequest();
        $_SERVER['HTTP_SEC_PURPOSE'] = 'quelque-chose-d-autre';

        $this->client()->pageview();

        $this->assertCount(1, self::$server->requests());
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

    /**
     * Le marqueur d'exclusion arrete la mesure.
     *
     * C'est la personne elle-meme qui le pose, en visitant n'importe quelle
     * URL du site avec `?qm_ignore=1`. Il ne contient aucun identifiant, n'est
     * jamais transmis a Quiet Metrics, et n'existe que pour cesser de compter :
     * c'est le marqueur de refus. Le SDK serveur doit l'honorer aussi bien que
     * le traceur JS, sans quoi le refus ne vaudrait que pour la moitie des
     * modes de suivi.
     */
    public function test_un_visiteur_qui_a_pose_le_marqueur_n_envoie_aucune_page_vue(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::OPT_OUT_MARKER] = '1';

        $this->client()->pageview();

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /** Un evenement serveur emis pour une personne exclue n'a pas plus eu lieu. */
    public function test_un_visiteur_qui_a_pose_le_marqueur_n_envoie_aucun_evenement(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::OPT_OUT_MARKER] = '1';

        $this->client()->event('achat', ['montant' => 49]);

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /**
     * Valeurs de cookie qui ne valent PAS un refus.
     *
     * @return array<string, array{0:string}>
     */
    public static function marqueursQuiNExcluentPas(): array
    {
        return [
            'refus retire' => ['0'],
            'valeur vide' => [''],
            'valeur inconnue' => ['oui'],
        ];
    }

    /**
     * Seule la valeur `1` exclut, exactement comme la lit le traceur JS.
     *
     * Traiter la seule PRESENCE du cookie comme un refus ferait disparaitre
     * des visites reelles : `qm_ignore=0` est justement la trace laissee par
     * un retrait, et un cookie vide est ce que renvoient certains proxys.
     */
    #[DataProvider('marqueursQuiNExcluentPas')]
    public function test_un_marqueur_qui_ne_vaut_pas_un_laisse_partir_la_page_vue(string $valeur): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::OPT_OUT_MARKER] = $valeur;

        $this->client()->pageview();

        $this->assertCount(1, self::$server->requests(), 'cookie qm_ignore='.$valeur.' : ce n\'est pas un refus');
    }

    /**
     * Le nom et la duree du marqueur, figes contre une modification distraite.
     *
     * CE QUE CE TEST NE FAIT PAS, et son docblock l'a affirme a tort : il ne
     * lit PAS packages/tracker-js/tracker.js, il compare la constante a un
     * litteral ecrit trois lignes plus bas. Renommer le marqueur dans le
     * traceur le laisse donc vert. Producteur et consommateur eprouves chacun
     * contre sa propre copie de la verite, c'est exactement la que se logent
     * les bugs silencieux, et un faux garde est pire que pas de garde.
     *
     * La confrontation reelle des deux fichiers vit dans
     * apps/platform/tests/Unit/MarqueurExclusionContratTest.php, seul endroit
     * du depot qui voie tous les paquets a la fois. Ce test-ci ne garde que
     * le cote PHP, et c'est tout ce qu'il pretend faire.
     */
    public function test_le_marqueur_porte_le_nom_et_la_duree_attendus(): void
    {
        $this->assertSame('qm_ignore', Client::OPT_OUT_MARKER);
        $this->assertSame(157680000, Client::OPT_OUT_LIFETIME, 'cinq ans, comme le max-age pose par le traceur JS');
    }

    /** Le signal d'URL dit quoi faire, ou ne dit rien du tout. */
    public function test_le_signal_d_url_pose_retire_ou_se_tait(): void
    {
        $this->assertTrue(Client::optOutSignal('1'), '?qm_ignore=1 pose le refus');
        $this->assertFalse(Client::optOutSignal('0'), '?qm_ignore=0 le retire');

        foreach ([null, '', '2', 'true', 'oui'] as $sansSignal) {
            $this->assertNull(
                Client::optOutSignal($sansSignal),
                'toute autre valeur ne dit rien, et surtout ne retire pas un refus existant',
            );
        }
    }

    /**
     * La visite qui pose le refus ne se compte pas elle-meme.
     *
     * Le traceur JS pose le marqueur puis relit son propre cookie avant de
     * decider : la page portant `?qm_ignore=1` n'est deja pas comptee. Le SDK
     * serveur doit se comporter pareil, sinon la premiere action de la
     * personne serait justement de se faire mesurer.
     */
    public function test_la_requete_qui_pose_le_marqueur_n_est_pas_comptee(): void
    {
        $this->fakeRequest();
        $_GET[Client::OPT_OUT_MARKER] = '1';

        Client::handleOptOutRequest();

        $this->assertTrue(
            Client::isOptedOut($_COOKIE[Client::OPT_OUT_MARKER] ?? null),
            'le processus en cours doit voir le refus qu\'il vient de poser',
        );

        $this->client()->pageview();

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /** Retirer le marqueur remet la personne dans la mesure des cette requete. */
    public function test_la_requete_qui_retire_le_marqueur_est_comptee(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::OPT_OUT_MARKER] = '1';
        $_GET[Client::OPT_OUT_MARKER] = '0';

        Client::handleOptOutRequest();

        $this->assertFalse(Client::isOptedOut($_COOKIE[Client::OPT_OUT_MARKER] ?? null));

        $this->client()->pageview();

        $this->assertCount(1, self::$server->requests());
    }

    /** Sans signal dans l'URL, un refus deja pose reste intact. */
    public function test_sans_signal_dans_l_url_le_marqueur_existant_n_est_pas_touche(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::OPT_OUT_MARKER] = '1';

        Client::handleOptOutRequest();

        $this->assertSame('1', $_COOKIE[Client::OPT_OUT_MARKER] ?? null);

        $this->client()->pageview();

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /**
     * Continuite de visite.
     *
     * Le cookie `qm_visit` vaut `1` chez tout le monde : il n'identifie
     * personne, il dit seulement qu'une visite est deja en cours sur ce
     * navigateur. Le hit le reporte dans `c`, ce qui evite de compter deux
     * visiteurs uniques quand l'empreinte change en cours de visite (4G puis
     * wifi). Le SDK serveur doit le lire comme le traceur JS l'ecrit.
     */
    public function test_le_cookie_de_visite_porte_le_nom_et_la_duree_attendus(): void
    {
        // Fige le contrat cote PHP, et rien de plus : la confrontation avec
        // packages/tracker-js/tracker.js ne peut vivre ici, ce paquet est
        // publie seul sur Packagist et ne voit pas le traceur.
        $this->assertSame('qm_visit', Client::VISIT_MARKER);
        $this->assertSame(600, Client::VISIT_LIFETIME, 'dix minutes, comme le max-age pose par le traceur JS');
    }

    /**
     * Valeurs de cookie qui ne valent PAS une visite en cours.
     *
     * @return array<string, array{0:string}>
     */
    public static function cookiesQuiNOuvrentPasDeVisite(): array
    {
        return [
            'valeur vide' => [''],
            'zero' => ['0'],
            'valeur inconnue' => ['oui'],
        ];
    }

    /**
     * Seule la valeur `1` vaut une visite en cours, comme pour le refus.
     *
     * Se contenter de la PRESENCE du cookie ferait passer pour une visite
     * continue tout ce qu'un intermediaire laisse trainer sous ce nom, et
     * fusionnerait alors deux personnes en une.
     */
    #[DataProvider('cookiesQuiNOuvrentPasDeVisite')]
    public function test_un_cookie_de_visite_qui_ne_vaut_pas_un_n_ouvre_rien(string $valeur): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::VISIT_MARKER] = $valeur;

        $this->client()->pageview();

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertArrayNotHasKey('c', $payload, 'cookie qm_visit='.$valeur.' : ce n\'est pas une visite en cours');
    }

    public function test_une_visite_en_cours_ajoute_c_au_payload(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::VISIT_MARKER] = '1';

        $this->client()->pageview();

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame(1, $payload['c']);
    }

    /** Sans cookie, la cle est absente : `c` ne vaut que `1`, jamais `0`. */
    public function test_sans_visite_en_cours_le_payload_ne_porte_pas_de_c(): void
    {
        $this->fakeRequest();

        $this->client()->pageview();

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertArrayNotHasKey('c', $payload);
    }

    /**
     * La surcharge `visit` prime sur `$_COOKIE`.
     *
     * C'est par elle que Laravel et Symfony transmettent ce qu'ils ont lu sur
     * leur objet Request : sous Octane, RoadRunner ou FrankenPHP, `$_COOKIE`
     * peut appartenir a la requete precedente, et la visite d'un visiteur
     * serait alors recollee a celle du suivant.
     */
    public function test_la_surcharge_visit_prime_sur_le_cookie(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::VISIT_MARKER] = '1';

        $this->client()->pageview(['visit' => false]);
        $this->client()->event('achat', [], ['visit' => true]);

        $requests = self::$server->requests(2);
        $this->assertArrayNotHasKey('c', json_decode($requests[0]['body'], true));
        $this->assertSame(1, json_decode($requests[1]['body'], true)['c']);
    }

    /**
     * `handleVisitRequest()` dit ce qui etait vrai AVANT son ecriture.
     *
     * Sans quoi le hit qui suit se declarerait toujours en visite continue,
     * puisque c'est lui-meme qui vient d'ouvrir la fenetre.
     */
    public function test_handle_visit_request_dit_l_etat_d_avant_et_ne_touche_pas_a_cookie(): void
    {
        $this->fakeRequest();

        $this->assertFalse(Client::handleVisitRequest(), 'aucune visite en cours au moment du hit');

        // `$_COOKIE` reste intact, a l'inverse du refus : le hit qui part
        // ensuite (WordPress envoie sur shutdown) doit lire l'etat d'avant.
        $this->assertArrayNotHasKey(Client::VISIT_MARKER, $_COOKIE);

        $this->client()->pageview();

        $this->assertArrayNotHasKey('c', json_decode(self::$server->requests()[0]['body'], true));
    }

    public function test_handle_visit_request_reconnait_une_visite_deja_ouverte(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::VISIT_MARKER] = '1';

        $this->assertTrue(Client::handleVisitRequest());
    }

    /**
     * On n'ecrit RIEN chez quelqu'un qui a refuse la mesure.
     *
     * Defense en profondeur : les appelants ne posent deja le cookie que sur
     * un hit mesure, et un hit ne part pas pour une personne exclue. Cette
     * garde tient meme si un appelant l'oublie. Seul le retour est observable
     * en CLI, `headers_list()` y etant vide ; l'absence de Set-Cookie, elle,
     * est eprouvee sur la Response par les ponts Laravel et Symfony.
     */
    public function test_handle_visit_request_n_ouvre_pas_de_visite_chez_une_personne_exclue(): void
    {
        $this->fakeRequest();
        $_COOKIE[Client::OPT_OUT_MARKER] = '1';
        $_COOKIE[Client::VISIT_MARKER] = '1';

        $this->assertFalse(
            Client::handleVisitRequest(),
            'un refus posé arrete tout, y compris la continuite de visite',
        );
    }
}
