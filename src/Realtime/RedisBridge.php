<?php

declare(strict_types=1);

namespace App\Realtime;

use Clue\React\Redis\Client;

/*
 * PUENTE Redis -> WebSockets.
 *
 * Se suscribe al canal de pub/sub de Redis donde la API publica los eventos
 * del dominio (p. ej. "items.stock" cuando se hace un pedido) y reenvía cada
 * mensaje al RealtimeHub, que lo difunde a todos los clientes conectados.
 *
 * Este server NO necesita Redis conectado para arrancar con WebSocket: si la
 * suscripción falla (Redis caído), los clientes siguen pudiendo conectarse y
 * simplemente no llegan eventos hasta que Redis vuelva.
 */
final class RedisBridge
{
    public function __construct(
        private readonly Client $redis,
        private readonly RealtimeHub $hub,
        private readonly string $channel = 'items.stock',
    ) {
    }

    /** Suscribe al canal y enruta los mensajes hacia el hub. */
    public function start(): void
    {
        $this->redis->on('message', function (string $channel, string $payload): void {
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                return;
            }

            error_log(sprintf('[realtime] Evento en "%s" (%d bytes)', $channel, strlen($payload)));
            $this->hub->broadcast($data);
        });

        $this->redis->subscribe($this->channel);
    }
}