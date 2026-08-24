<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

/**
 * Mini serveur HTTP de capture pour les tests de transport : démarre le
 * serveur PHP intégré avec un routeur qui journalise chaque requête (méthode,
 * en-têtes, corps) en JSON-lines, puis relit le journal.
 *
 * Le port n'est jamais deviné : le noyau en attribue un libre (bind sur le
 * port 0) et on le passe à « php -S ». Il subsiste une fenêtre minuscule entre
 * la fermeture de la sonde et le bind du serveur, pendant laquelle un autre
 * processus peut rafler le port ; le serveur meurt alors au démarrage et on
 * recommence sur un port neuf. Le budget total est plafonné pour qu'un vrai
 * blocage échoue au lieu d'immobiliser la CI.
 */
final class CaptureServer
{
    /** Budget d'attente total au démarrage, toutes tentatives confondues. */
    private const BUDGET_DEMARRAGE = 15.0;

    /** Nombre de ports essayés avant d'abandonner. */
    private const TENTATIVES_MAX = 5;

    /** @var resource|null */
    private $process;

    private int $port = 0;

    private string $logFile = '';

    private string $stderrFile = '';

    /** Secret prouvant que le serveur qui répond sur le port est bien le nôtre. */
    private string $jeton = '';

    public function start(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'qm-capture-');
        $this->stderrFile = tempnam(sys_get_temp_dir(), 'qm-capture-err-');
        $this->jeton = bin2hex(random_bytes(16));

        $limite = microtime(true) + self::BUDGET_DEMARRAGE;
        $echecs = [];

        for ($tentative = 1; $tentative <= self::TENTATIVES_MAX; $tentative++) {
            $port = $this->portLibre();
            if ($port === null) {
                $echecs[] = 'impossible de réserver un port sur 127.0.0.1';

                break;
            }

            $echec = $this->demarrerSur($port, $limite);
            if ($echec === null) {
                $this->port = $port;

                return;
            }

            $this->arreterProcessus();
            $echecs[] = sprintf('tentative %d (port %d) : %s', $tentative, $port, $echec);

            // Un port raflé entre la sonde et le bind se rejoue aussitôt sur un
            // port neuf ; un budget épuisé, lui, ne se rattrape pas.
            if (microtime(true) >= $limite) {
                break;
            }
        }

        $this->nettoyerFichiers();

        throw new \RuntimeException(
            "Serveur de capture injoignable.\n  ".implode("\n  ", $echecs)
        );
    }

    public function stop(): void
    {
        $this->arreterProcessus();
        $this->nettoyerFichiers();
    }

    public function endpoint(): string
    {
        return sprintf('http://127.0.0.1:%d/api/v1/collect', $this->port);
    }

    /** Vide le journal (isolation entre tests). */
    public function reset(): void
    {
        file_put_contents($this->logFile, '');
    }

    /**
     * Requêtes capturées. Réessaie brièvement : l'envoi fire-and-forget peut
     * atterrir quelques millisecondes après le retour du client.
     *
     * @return list<array{method:string, uri:string, headers:array<string,string>, body:string}>
     */
    public function requests(int $expected = 1, int $timeoutMs = 3000): array
    {
        $deadline = microtime(true) + $timeoutMs / 1000;

        do {
            $lines = @file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if (count($lines) >= $expected) {
                return array_map(
                    static fn (string $line): array => json_decode($line, true),
                    $lines,
                );
            }
            usleep(30_000);
        } while (microtime(true) < $deadline);

        return [];
    }

    /**
     * Port libre attribué par le noyau. On ouvre puis referme aussitôt : c'est
     * la seule façon d'obtenir un port dont on sait qu'il était libre.
     */
    private function portLibre(): ?int
    {
        $sonde = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sonde === false) {
            return null;
        }

        $adresse = stream_socket_get_name($sonde, false);
        fclose($sonde);

        if (! is_string($adresse)) {
            return null;
        }

        $separateur = strrpos($adresse, ':');
        if ($separateur === false) {
            return null;
        }

        return (int) substr($adresse, $separateur + 1);
    }

    /**
     * Lance le serveur sur ce port et attend qu'il réponde.
     *
     * @return string|null null si le serveur répond, sinon la raison de l'échec
     */
    private function demarrerSur(int $port, float $limite): ?string
    {
        // 'w' tronque à l'ouverture : la stderr lue appartient bien à cette
        // tentative et pas à la précédente.
        file_put_contents($this->stderrFile, '');

        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/capture-router.php'],
            [2 => ['file', $this->stderrFile, 'w']],
            $pipes,
            null,
            ['WA_CAPTURE_LOG' => $this->logFile, 'WA_CAPTURE_READY' => $this->jeton],
        );

        if (! is_resource($this->process)) {
            return sprintf('proc_open a échoué, %s n\'a pas pu être lancé', PHP_BINARY);
        }

        while (microtime(true) < $limite) {
            $etat = proc_get_status($this->process);
            if (is_array($etat) && ! $etat['running']) {
                return sprintf(
                    'le processus s\'est arrêté au démarrage (code %d)%s',
                    $etat['exitcode'],
                    $this->stderrLisible(),
                );
            }

            if ($this->repond($port)) {
                return null;
            }

            usleep(50_000);
        }

        return sprintf(
            'le processus tourne toujours mais n\'a pas répondu dans le budget de %.0f s%s',
            self::BUDGET_DEMARRAGE,
            $this->stderrLisible(),
        );
    }

    /**
     * Le serveur répond-il sur ce port, et est-ce bien LE NÔTRE ? Se contenter
     * d'ouvrir une connexion ne prouve rien : n'importe quel processus ayant
     * raflé le port ferait passer la sonde, et l'échec ressortirait plus tard
     * sous une forme incompréhensible. Le jeton tranche la question.
     */
    private function repond(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, 0, 500_000);
        fwrite($socket, "GET /__pret HTTP/1.0\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n");
        $reponse = (string) stream_get_contents($socket);
        fclose($socket);

        return strpos($reponse, $this->jeton) !== false;
    }

    /** Stderr du serveur, indentée pour rester lisible dans le message d'échec. */
    private function stderrLisible(): string
    {
        $contenu = trim((string) @file_get_contents($this->stderrFile));

        if ($contenu === '') {
            return ' [stderr vide]';
        }

        return "\n    stderr : ".str_replace("\n", "\n             ", $contenu);
    }

    private function arreterProcessus(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }

        $this->process = null;
    }

    private function nettoyerFichiers(): void
    {
        foreach ([$this->logFile, $this->stderrFile] as $fichier) {
            if ($fichier !== '') {
                @unlink($fichier);
            }
        }
    }
}
