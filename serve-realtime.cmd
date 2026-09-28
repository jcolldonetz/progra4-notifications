@echo off
REM Inicia el server de notificaciones realtime (WebSockets + Redis pub/sub).
REM Requisitos:
REM   1. Redis corriendo en 127.0.0.1:6379 (canal de pub/sub "items.stock").
REM   2. La API con REDIS_URL configurado (para que publique los eventos).
REM   3. JWT_SECRET igual al de la API (si cambiaste el default).
if not exist vendor\autoload.php (
    call composer install --no-interaction
    if errorlevel 1 exit /b 1
)
php bin\notif-server.php