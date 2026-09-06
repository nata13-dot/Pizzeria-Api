-- Pizzería POS · preferencias individuales de notificación por dispositivo
-- Motor: PostgreSQL (Railway)
-- Seguro para ejecutar más de una vez.

BEGIN;

ALTER TABLE user_devices
    ADD COLUMN IF NOT EXISTS notification_sound_mode VARCHAR(10) NOT NULL DEFAULT 'fixed';

ALTER TABLE user_devices
    ADD COLUMN IF NOT EXISTS notification_channels JSON NULL;

-- Los dispositivos ya registrados seguirán usando el tono original.
UPDATE user_devices
SET notification_sound_mode = 'fixed',
    notification_channels = '["orders_arrival_tone_v3_default"]'::json
WHERE notification_channels IS NULL;

COMMIT;

-- Verificación opcional:
-- SELECT id, name, platform, notification_sound_mode, notification_channels
-- FROM user_devices
-- ORDER BY id;
