# Despliegue con recursos limitados

Los cambios de código agregan caché del catálogo POS, categorías y combos por sucursal. Los permisos y la validación de parámetros se ejecutan antes de leer la caché. Productos, variantes, sabores, modificadores y combos invalidan el catálogo al guardar o eliminar, también después de confirmar una transacción. No se publican datos de transacciones abiertas. Las claves son fijas por sucursal y tipo de catálogo: editar repetidamente no crea archivos nuevos por versión.

La app utiliza una sola caché con solicitudes GET simultáneas compartidas, hasta 48 entradas y aproximadamente 4 MiB de contenido JSON en memoria (no es un límite de toda la memoria de JavaScript). Solo persiste seis catálogos sin parámetros, con máximo aproximado de 2 MiB por entrada. Pedidos, caja, reportes, clientes e inventario no se escriben en almacenamiento persistente ni se recuperan silenciosamente como datos antiguos cuando la red falla. Al iniciar se elimina la caché v1 anterior. Cerrar sesión impide que solicitudes anteriores vuelvan a poblar la caché.

## Activación en un único servidor

En el `.env` de la API:

```dotenv
APP_ENV=production
APP_DEBUG=false
CORS_ALLOWED_ORIGINS=https://espinazodeldiablo.site,https://localhost
CATALOG_CACHE_ENABLED=true
CATALOG_CACHE_STORE=file
CATALOG_CACHE_TTL=300
PERFORMANCE_METRICS_ENABLED=false
```

La caché de archivos utiliza `storage/framework/cache/data`; PHP-FPM debe poder escribir ahí. No requiere Redis ni otro proceso residente. Laravel soporta este almacenamiento: [documentación de caché](https://laravel.com/docs/12.x/cache). En varias réplicas se necesita un almacén compartido; los archivos locales no coordinan invalidaciones entre servidores.

Si el login muestra un error CORS, actualizar `CORS_ALLOWED_ORIGINS` en el `.env` real de la API y ejecutar `php artisan config:cache`. Conservar otros orígenes legítimos que ya se utilicen. Un preflight `204` sin `Access-Control-Allow-Origin` no permite iniciar sesión desde ese origen. Verificar después del cambio:

```sh
curl -i -X OPTIONS https://api.espinazodeldiablo.site/api/login \
  -H 'Origin: https://espinazodeldiablo.site' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: content-type'
```

La respuesta debe incluir `Access-Control-Allow-Origin: https://espinazodeldiablo.site`. Este dominio está autorizado directamente en `config/cors.php`; `CORS_ALLOWED_ORIGINS` agrega otros clientes. Tras actualizar el repositorio, ejecutar `php artisan config:cache` para que el servidor deje de usar la lista anterior. No se resuelve añadiendo headers al frontend ni usando `fetch` con `no-cors`.

Después de subir el código, ejecutar en `Pizzeria-Api` con el mismo usuario de despliegue y permisos de la aplicación:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Reiniciar los workers de aplicación existentes después del despliegue para que carguen los observadores nuevos. Conservar la configuración actual de colas y Reverb; las notificaciones dependen de ella. No ejecutar `composer dev`, Metro ni Vite de desarrollo en producción.

## Frontend estático

Compilar `Pizzeria` en la máquina de desarrollo o CI y copiar `dist` al servidor:

```sh
npm ci
npm run build:web
```

La variable `EXPO_PUBLIC_API_URL` debe corresponder al servidor de producción durante esa compilación. Servir `dist` directamente con Nginx elimina la necesidad del proceso Node `serve` para el frontend. Esto permite trasladar la compilación fuera del equipo Debian de 32 bits.

Integrar `deploy/debian/nginx-static.conf.example` en el servidor virtual del frontend conservando dominio, TLS y rutas existentes. La caché larga solo se aplica a bundles con huella en el nombre; `index.html` se revalida para recibir despliegues nuevos. Compresión nivel 2 limita el trabajo por respuesta; referencia: [gzip de Nginx](https://nginx.org/en/docs/http/ngx_http_gzip_module.html). No aplicar caché pública ni FastCGI cache a respuestas autenticadas de la API.

## PHP-FPM

Los ejemplos `deploy/debian/php-low-memory.ini` y `deploy/debian/fpm-pool.conf.example` son un punto de partida que se debe ajustar a la RAM disponible: máximo dos procesos FPM bajo demanda, 128 MiB por petición y 64 MiB de OPcache compartido. No son una garantía de consumo total ni valores medidos en tu servidor. Conservar socket, usuario y grupo del pool existente. Verificar la configuración con el binario FPM instalado y `nginx -t` antes de recargar esos servicios.

Medir el RSS de los procesos durante venta, impresión de PDF y reportes. Reservar memoria para MariaDB, Debian y Reverb; reducir concurrencia si comienza a usarse swap. Referencias: [procesos FPM](https://www.php.net/manual/en/install.fpm.configuration.php), [OPcache](https://www.php.net/manual/en/opcache.configuration.php).

## Frescura, operación y verificación

- Caché del servidor: cinco minutos por defecto. Las modificaciones mediante los modelos de la aplicación la invalidan. SQL directo, como `railway/load_tricombos.sql`, no dispara observadores: después de importar ejecutar `php artisan tinker --execute='app(\App\Services\CatalogCache::class)->invalidate();'`.
- Caché local de catálogos: cinco minutos; pedidos, ocho segundos; otros listados, un minuto. Las modificaciones hechas desde ese cliente invalidan sus recursos relacionados. Otros dispositivos ven cambios de catálogo al vencer su caché o pulsar recargar. Una recarga forzada consulta la API.
- Solo los catálogos persistidos pueden usarse como respaldo sin conexión, hasta siete días, cuando una lectura normal intenta actualizarse y falla por red/servidor. Los errores de permisos no usan ese respaldo. La venta siempre se valida nuevamente en la API.
- Las pruebas verifican que el catálogo caliente no ejecuta consultas a tablas de catálogo, y comprueban invalidación de precios/modificadores, aislamiento por sucursal, permisos, vencimiento y protección ante respuestas tardías. Esto no equivale a medir latencia ni RAM del Debian real.
- Para comparar solicitudes frías/calientes activar temporalmente `PERFORMANCE_METRICS_ENABLED=true`, `PERFORMANCE_METRICS_LOG=false`, regenerar `config:cache` e inspeccionar `X-Performance-Metrics` en `/api/pos/catalog`. Restablecer las métricas a `false` al terminar.
- Para deshabilitar la nueva caché del servidor: `CATALOG_CACHE_ENABLED=false` y `php artisan config:cache`.

El despliegue y las plantillas no se aplicaron al servidor remoto desde este workspace.
