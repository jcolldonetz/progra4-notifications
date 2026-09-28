<?php

declare(strict_types=1);

use App\Realtime\RealtimeHub;
use App\Realtime\RedisBridge;
use App\Security\JwtVerifier;
use Clue\React\Redis\Factory;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

/*
 * PUNTO DE ENTRADA del server de notificaciones realtime.
 *
 * Levanta un server WS (Ratchet) en WS_PORT (default 8081) que autentica
 * cada conexión con el JWT (?token=) y un puente a Redis (REDIS_URL) que se
 * suscribe al canal "items.stock" y difunde cada evento a todos los clientes.
 *
 * Se monta con IoServer directamente (sin Rusty\App ni routing por host) para
 * que el puerto sea dedicado y acepte cualquier Host/path que apunte al /ws.
 *
 * Variables de entorno (ver .env.example):
 *   WS_PORT=8081            Puerto donde escucha el WebSocket.
 *   WS_HOST=127.0.0.1       Interfaz donde escucha.
 *   REDIS_URL=127.0.0.1:6379  Dónde está Redis (canal: items.stock).
 *   JWT_SECRET=...          MISMO secreto que la API (obligatorio).
 *   CORS_ALLOWED_ORIGINS=   Orígenes permitidos (separados por coma).
 *
 * Ejecutar:
 *   php bin/notif-server.php
 */

// PHP 8.5 marca como deprecados algunos patrones internos de Ratchet/React
// (parámetros nullable implícitos, propiedades dinámicas). Este server es un
// proceso long-running: ocultamos dichos avisos para mantener el log limpio.
error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

$wsPort   = (int) (getenv('WS_PORT') ?: 8081);
$wsHost   = getenv('WS_HOST') ?: '127.0.0.1';
$redisUrl = getenv('REDIS_URL') ?: '127.0.0.1:6379';
$secret   = getenv('JWT_SECRET') ?: 'secreto-solo-para-desarrollo-cambiar';
$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:5173,http://127.0.0.1:5173'),
)));

if ($secret === '') {
    fwrite(STDERR, "JWT_SECRET no puede estar vacío.\n");
    exit(1);
}

echo "Visor de notificaciones realtime\n";
echo sprintf("  WebSocket : ws://%s:%d/ws\n", $wsHost, $wsPort);
echo sprintf("  Redis     : %s (canal: items.stock)\n", $redisUrl);

$loop = Loop::get();

$jwt = new JwtVerifier($secret);
$hub = new RealtimeHub($jwt, $allowedOrigins);

$redis = (new Factory($loop))->createLazyClient($redisUrl);
(new RedisBridge($redis, $hub, 'items.stock'))->start();

$socket = new SocketServer(sprintf('%s:%d', $wsHost, $wsPort), [], $loop);
$server = new IoServer(new HttpServer(new WsServer($hub)), $socket, $loop);
unset($server);

echo "  Escuchando... (Ctrl+C para detener)\n";
$loop->run();