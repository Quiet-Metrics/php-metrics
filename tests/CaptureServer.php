<?php

declare(strict_types=1);

namespace QuietMetrics\Tests;

/**
 * Mini serveur HTTP de capture pour les tests de transport : démarre le
 * serveur PHP intégré avec un routeur qui journalise chaque requête (méthode,
 * en-têtes, corps) en JSON-lines, puis relit le journal.
 */
final class CaptureServer
{
    /** @var resource|null */
    private $process;

    private int $port;

    private string $logFile;

    public function start(): void
    {
        $this->port = random_int(40000, 49999);
        $this->logFile = tempnam(sys_get_temp_dir(), 'qm-capture-');

        $router = __DIR__.'/capture-router.php';
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $router],
            [2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['WA_CAPTURE_LOG' => $this->logFile],
        );

        // Attend que le serveur accepte les connexions (max ~2 s).
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.05);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }

        throw new \RuntimeException('Serveur de capture injoignable.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->logFile);
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
}
