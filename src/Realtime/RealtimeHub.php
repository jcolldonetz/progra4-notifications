<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Security\JwtVerifier;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;

/*
 * HUB de WebSockets: mantiene las conexiones abiertas y reenvía los eventos
 * recibidos desde Redis a TODOS los clientes conectados.
 *
 * Seguridad sobre el protocolo (el navegador no permite headers personalizados
 * en el handshake de WebSocket, así que el JWT viaja en el query string):
 *   1. Origin: si viene el header Origin, debe pertenecer a la lista blanca
 *      (CORS_ALLOWED_ORIGINS); si no, se rechaza con código 4403.
 *   2. Autenticación: se exige un JWT válido en ?token=; si no, 4401.
 *
 * La dirección es unidireccional server -> client: los mensajes que el cliente
 * envíe se ignoran (el cliente realtime no necesita hablar con el server).
 */
final class RealtimeHub implements MessageComponentInterface
{
    /** @var SplObjectStorage<ConnectionInterface, null> */
    private SplObjectStorage $connections;

    /** @var list<string> */
    private array $allowedOrigins;

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private readonly JwtVerifier $jwt,
        array $allowedOrigins = [],
    ) {
        $this->connections = new SplObjectStorage();
        // Por defecto: el dev server de Vite en localhost/127.0.0.1.
        $this->allowedOrigins = $allowedOrigins ?: ['http://localhost:5173', 'http://127.0.0.1:5173'];
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        // 1) Validación de Origin (cuando el cliente la envía; siempre en navegadores).
        $origin = $this->firstHeader($conn, 'Origin');
        if ($origin !== '' && !in_array($origin, $this->allowedOrigins, true)) {
            $this->reject($conn, 4403, 'Origin no permitido.');
            return;
        }

        // 2) Autenticación con el JWT ((?token=...)).
        $token = $this->tokenFromQuery($conn);
        if ($token === null || $this->jwt->verify($token) === null) {
            $this->reject($conn, 4401, 'Token JWT inválido o expirado.');
            return;
        }

        $this->connections->attach($conn);
        error_log(sprintf(
            '[realtime] Cliente conectado (%s). Conectados: %d',
            $this->firstHeader($conn, 'User-Agent') ?: 'desconocido',
            $this->connections->count(),
        ));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // Unidireccional: los mensajes del cliente se ignoran.
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->connections->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log('[realtime] Error en conexión: ' . $e->getMessage());
        $conn->close(1011);
        $this->connections->detach($conn);
    }

    /** Envía el evento (array JSON) a todos los clientes conectados. */
    public function broadcast(array $payload): void
    {
        $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($message === false) {
            return;
        }

        foreach ($this->connections as $conn) {
            $conn->send($message);
        }
    }

    public function count(): int
    {
        return $this->connections->count();
    }

    private function reject(ConnectionInterface $conn, int $code, string $reason): void
    {
        error_log("[realtime] Conexión rechazada ({$code}): {$reason}");
        $conn->close($code);
    }

    private function tokenFromQuery(ConnectionInterface $conn): ?string
    {
        $query = $conn->httpRequest?->getUri()?->getQuery() ?? '';
        parse_str($query, $params);
        $token = $params['token'] ?? '';

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function firstHeader(ConnectionInterface $conn, string $name): string
    {
        $values = $conn->httpRequest?->getHeader($name) ?? [];

        return isset($values[0]) && is_string($values[0]) ? $values[0] : '';
    }
}