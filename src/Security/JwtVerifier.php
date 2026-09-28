<?php

declare(strict_types=1);

namespace App\Security;

/*
 * Verificación de tokens JWT (HS256) para el server de notificaciones.
 *
 * Es la contraparte "read-only" del JwtService de la API (proyecto
 * progra4-api). La API emite/usa la misma firma y este server solo necesita
 * VALIDAR los tokens que llegan por WebSocket en el query param "token":
 *   - firma HMAC-SHA256 en tiempo constante (hash_equals),
 *   - algoritmo declarado en el header,
 *   - vigencia (claim exp).
 * Debe configurarse con el MISMO JWT_SECRET que la API.
 */
final class JwtVerifier
{
    private const ALG = 'HS256';

    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new \RuntimeException('JWT_SECRET no puede estar vacío.');
        }
    }

    /**
     * @return array<string, mixed>|null los claims si el token es válido; null en caso contrario.
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $signature] = $parts;

        // 1) Firma: comparación en tiempo constante.
        if (!hash_equals($signature, $this->signature($header . '.' . $body))) {
            return null;
        }

        // 2) Header: el algoritmo declarado debe ser el que usamos.
        $rawHeader = $this->base64UrlDecode($header);
        if ($rawHeader === false) {
            return null;
        }
        $headerData = json_decode($rawHeader, true);
        if (!is_array($headerData) || ($headerData['alg'] ?? '') !== self::ALG) {
            return null;
        }

        // 3) Payload decodificable...
        $rawBody = $this->base64UrlDecode($body);
        if ($rawBody === false) {
            return null;
        }
        $claims = json_decode($rawBody, true);
        if (!is_array($claims)) {
            return null;
        }

        // 4) ...y vigente (exp en segundos Unix).
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    /** HMAC-SHA256 codificado en base64url: la firma del token. */
    private function signature(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}