# progra4-notifications — Notificaciones realtime (Programación 4)

Server de **notificaciones en tiempo real** para la app del curso: recibe los
eventos que publica la API de [`progra4-api`](../progra4-api) y los entrega a
los navegadores conectados por **WebSocket**. Su caso de uso: cuando un cliente
hace un pedido (`POST /pedidos`) y el stock baja, los **demás** clientes ven el
stock actualizado en vivo y un toast de aviso, sin tener que recargar ni
refetch.

Su objetivo didáctico es mostrar el **patrón publisher/subscriber con
middleware**: la API *publica* en un canal compartido (Redis pub/sub), este
proyecto *se suscribe* a ese canal y *difunde* a las conexiones WebSocket, sin
que la API conozca a los clientes conectados.

## Stacks

| Capa | Tecnología |
|---|---|
| WebSockets | **Ratchet** (`cboden/ratchet` ^0.4.4) sobre **ReactPHP** (`React\EventLoop`, `React\Socket\SocketServer`) |
| Redis async | **clue/redis-react** (`Clue\React\Redis\Client`, ^2.0) — pub/sub no bloqueante en el mismo event loop |
| Seguridad | Verificación **JWT HS256** a mano (`JwtVerifier`, contraparte *read-only* del `JwtService` de la API) |
| Lenguaje | PHP >= 8.1 (proceso long-running, Composer para dependencias) |

## Arquitectura

```
                          ┌────────────────────────────────────────────────┐
                          │          progra4-notifications                 │
                          │                                                │
 progra4-web              │   ┌─────────────┐  mensaje  ┌───────────────┐  │
 (navegador)              │   │ RedisBridge │ ─────────▶│  RealtimeHub  │──┼──▶ WS
 ┌─────────────────┐      │   │ (suscribe   │           │               │  │   ▲
 │ RealtimeToasts  │◀─ WebSocket (ws://…/ws  │           │ valida: Origin│  │   │
 │  + stock en vivo│    por proxy de Vite)   │           │        + JWT  │  │   │
 └─────────────────┘      │   │ items.stock) │           └───────────────┘  │   │
                          │   └──────┬──────┘                                │   │
                          │          │  pub/sub (Redis)                      │   │
                          └──────────┼───────────────────────────────────────┘   │
                                     │                                          │
 Redis (canal "items.stock") ▲       │                                          │
   único medio compartido     │       │                                          │
                                     v   publica                               │
                          ┌──────────────────────────────┐                     │
                          │        progra4-api           │                     │
                          │  POST /pedidos  ──desc stock─┤   (lo consume el    │
                          │  RedisEventPublisher ────────┼──▶ browser)         │
                          └──────────────────────────────┘                     │
                                     └──────────────────────────────────────────┘
```

La API y los clientes **no se conocen**: ambos hablan con Redis (pub/sub). Este
server es el puente intermedio que traduce del canal Redis al protocolo
WebSocket del navegador.

- **Unidireccional**: el server envía al cliente; los mensajes que el cliente
  mande se ignoran (el cliente realtime no necesita "hablar").
- **No conoce el camino de vuelta**: la difusión es `server -> client`, el
  pedido viaja HTTP → API → Redis → este server → WebSocket.

## ¿Para qué sirve Redis acá?

Redis es una base de datos **en memoria** que, entre otras cosas, brinda un
mecanismo de **pub/sub (publicador/suscriptor)**: un proceso *publica* un
mensaje en un **canal** con nombre y todos los procesos que están *suscritos* a
ese canal lo reciben —sin que el publicador conozca quién escucha, ni cuántos
son.

En este proyecto Redis **no se usa para guardar datos**, sino como **puente de
comunicación asincrónica** entre la API y el server de notificaciones. Hay un
solo canal configurado: `items.stock`.

### Ejemplo del caso de uso

Pensalo con dos navegadores abiertos (pestaña A y pestaña B) con la app:

1. En la **pestaña A**, alguien hace un pedido de 1 monitor. La API valida el
   stock, lo descuenta en SQLite y **publica** en Redis:
   ```powershell
   # lo que la API hace por dentro (vía predis), en el canal "items.stock"
   docker exec progra4-redis redis-cli publish items.stock '{"type":"pedido.creado","pedido":{"cantidad":1},"item":{"id":1,"stock":84}}'
   ```
2. **Este server**, que está *suscrito* a ese canal, recibe el mensaje y lo
   reenvía por WebSocket a **todas** las conexiones activas (pestaña A y B).
3. Ambas pestañas actualizan el stock y muestran el toast — y la pestaña B
   **no hizo ningún pedido**: se enteró por el canal.

Ventajas de usar Redis como medio compartido en vez de conectar la API
directamente a las WebSockets:

- **Desacople total**: la API publica "lo que pasó" y no sabe quién está
  conectado; este server difunde y no sabe quién publicó. Cualquiera de los dos
  puede apagarse y reencender sin afectar al otro.
- **Escala horizontal**: pueden conectarse *varios* servers de notificaciones
  (uno por puerto/máquina) todos suscritos al mismo canal: la API publica una
  sola vez y todos difunden a sus propios clientes.
- **Nuevos suscriptores sin tocar código**: cualquier otro consumidor (un bot,
  un dashboard, un log) solo tiene que suscribirse al canal.

## Estructura

```
progra4-notifications/
├── bin/notif-server.php             Punto de entrada (IoServer + loop de ReactPHP)
├── src/
│   ├── Security/JwtVerifier.php     Valida el JWT de la API (MISMO secreto, HS256)
│   └── Realtime/
│       ├── RealtimeHub.php          Conexiones WS: valida Origin + JWT, hace broadcast
│       └── RedisBridge.php          Se suscribe al canal y reenvía cada evento al hub
├── composer.json                    cboden/ratchet + clue/redis-react
├── .env.example                     Variables de entorno documentadas
└── serve-realtime.cmd               Arranque rápido (composer install si falta vendor/)
```

## Requisitos

- PHP >= 8.1 (con extensión `sockets`/`openssl` que incluye XAMPP y la mayoría
  de instalaciones).
- [Composer](https://getcomposer.org/).
- Un **Redis** local en `127.0.0.1:6379`. Windows no trae `redis-server`; la
  alternativa usada en clase es correr solo el Redis en Docker (el server WS es
  PHP puro y no depende de Docker):

```powershell
docker run -d --name progra4-redis -p 127.0.0.1:6379:6379 redis:7-alpine
docker ps        # verificar que quedó corriendo
```

**Si `progra4-redis` ya existe**, ese `docker run` falla con `Conflict. The
container name "/progra4-redis" is already in use...` (el nombre no se puede
reutilizar mientras el contenedor siga existiendo). El contenedor se creó una
vez y se puede **reutilizar**: solo hay que encenderlo.

**Caso A — reutilizar el contenedor existente (lo normal).** Revisar su estado
y arrancarlo si no está corriendo:

```powershell
docker ps -a        # ver el estado (Running / Exited) de progra4-redis
docker start progra4-redis    # lo enciende si estaba detenido; no-op si ya corre
docker ps           # -> debe aparecer en la lista con estado Up
```

**Caso B — recrearlo desde cero** (solo si se lo quiere descartar; los datos no
persisten porque en esta demo Redis no guarda nada):

```powershell
docker rm -f progra4-redis     # elimina el contenedor viejo (running o no)
docker run -d --name progra4-redis -p 127.0.0.1:6379:6379 redis:7-alpine
```

> Si por esto no se quiere Docker, valen también [Memurai](https://www.memurai.com/)
> (drop-in de Redis para Windows) o Redis en WSL.

## Cómo iniciarlo (ambiente de desarrollo)

**1. Instalar dependencias** (primera vez, dentro de `progra4-notifications`):

```powershell
composer install
```

**2. Verificar que Redis responde:**

```powershell
docker exec progra4-redis redis-cli ping   # -> PONG
```

**3. Configurar variables de entorno** (ver `.env.example`). Las únicas
realmente importantes en dev:

| Variable | Default | Uso |
|---|---|---|
| `WS_PORT` | `8081` | Puerto del WebSocket |
| `WS_HOST` | `127.0.0.1` | Interfaz donde escucha |
| `REDIS_URL` | `127.0.0.1:6379` | Dónde está Redis (canal: `items.stock`) |
| `JWT_SECRET` | solo desarrollo | **Debe coincidir con el de la API** |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173,http://127.0.0.1:5173` | Orígenes permitidos |

```powershell
$env:JWT_SECRET='secreto-solo-para-desarrollo-cambiar'   # igual que en la API
php bin\notif-server.php      # o, sin tocar variables: serve-realtime.cmd
```

**4. Verificación**: arranca y muestra el puerto WS y la URL de Redis:

```
Visor de notificaciones realtime
  WebSocket : ws://127.0.0.1:8081/ws
  Redis     : 127.0.0.1:6379 (canal: items.stock)
  Escuchando... (Ctrl+C para detener)
```

**5. Levantar el resto del stack** (ver README de cada proyecto):

```powershell
# progra4-api: publicar en Redis al hacer pedidos
$env:REDIS_URL='127.0.0.1:6379'; $env:JWT_SECRET='secreto-solo-para-desarrollo-cambiar'
php -S localhost:8000 -t public

# progra4-web: el proxy /ws (ws:true) reenvía a http://localhost:8081
npm run dev        # reiniciar si ya estaba corriendo antes del cambio del proxy
```

## Seguridad

El navegador no puede mandar headers personalizados en el handshake de
WebSocket, así que la autenticación va en el *query string*, y además se valida
el *Origin*:

1. **Origin**: si el header `Origin` no está en `CORS_ALLOWED_ORIGINS`, se
   rechaza con código **4403** (`Origin no permitido.`).
2. **JWT**: se exige un token válido en `?token=...`; si falta, está alterado o
   expiró, se rechaza con **4401**. El token lo emite la API (`POST /login`),
   así que nadie se conecta sin pasar antes por el login de la aplicación.

```text
ws://localhost:8081/ws?token=<JWT-que-devolvio /login>
```

> El JWT viaja en la URL, por eso aparece en los logs del proxy/red: es el
> trade-off estándar de WebSockets. El token expira solo (`JWT_TTL_SECONDS`).

## Formato de los eventos

El proyecto **no define** el formato: difunde a los WebSockets **lo mismo que
llegó por Redis**. La API publica (canal `items.stock`), por ejemplo:

```json
{
  "type": "pedido.creado",
  "pedido": { "id": 5, "item_id": 1, "cantidad": 2, "total": 165.62, "username": "admin", "created_at": "…" },
  "item": { "id": 1, "nombre": "Monitores Samsung M5 24\" Smart Monitor", "precio": 82.81, "stock": 84 },
  "username": "admin"
}
```

En el log del server se ve cada evento recibido:
`[realtime] Evento en "items.stock" (N bytes)`.

## Probar sin el cliente web

Con un cliente WS conectado (o con `.NET ClientWebSocket`), publicar un evento
a mano en el canal:

```powershell
docker exec progra4-redis redis-cli publish items.stock '{"type":"prueba","item":{"id":1,"stock":9}}'
```

El server lo reenvía a **todas** las conexiones activas.

## Notas

- El server arranca aunque Redis esté caído: los WebSockets siguen aceptando
  conexiones y recién se pierden los eventos hasta que Redis vuelva (la
  suscripción es lazy y se reintenta al reconectar).
- `bin/notif-server.php` oculta `E_DEPRECATED` (PHP 8.5 marca avisos en
  Ratchet/React) para mantener el log limpio en un proceso long-running.
- Corre en primer plano: `Ctrl+C` para detenerlo. Para producción se usaría un
  gestor de procesos (Supervisor, pm2, NSSM en Windows).
- Se montó con `IoServer` a bajo nivel (sin `Ratchet\App` ni routing por host)
  porque el routing de `Ratchet\App` responde 404 si el Host no coincide
  exactamente; así el servidor acepta cualquier Host/`/ws` que apunte al puerto.