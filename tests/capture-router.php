<?php

// Routeur du serveur PHP intégré : capture chaque requête en JSON-lines.

// Sonde de démarrage. Le jeton prouve à CaptureServer que c'est bien CE
// serveur qui répond sur le port, et non un squatteur qui l'aurait raflé
// entre la réservation du port et le bind. Répondu avant la capture : la
// sonde ne doit jamais polluer le journal.
if (($_SERVER['REQUEST_URI'] ?? '') === '/__pret') {
    header('Content-Type: text/plain');
    echo (string) getenv('WA_CAPTURE_READY');

    return;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
    }
}

file_put_contents(
    getenv('WA_CAPTURE_LOG'),
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL,
    FILE_APPEND | LOCK_EX,
);

http_response_code(202);
header('Content-Type: application/json');
echo '{}';
