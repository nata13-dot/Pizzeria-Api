# Pizzería POS Inventario — API

API de Laravel para el sistema de ventas, inventario por lotes, compras, producción, cocina, reparto, clientes, puntos, caja y reportes de la pizzería.

## Requisitos

- PHP 8.3 o posterior, con extensiones SQLite, GD y mbstring.
- Composer.
- SQLite para desarrollo local. También puede configurarse MySQL o PostgreSQL mediante `.env`.
- El frontend Expo ubicado en la carpeta hermana `Pizzeria`.

## Instalación local

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

La configuración incluida usa:

- API: `http://127.0.0.1:8000`
- WebSocket/Reverb: `ws://127.0.0.1:8080`
- Base SQLite: `database/database.sqlite`

Arranca cada proceso en una terminal distinta:

```bash
php artisan serve --host=0.0.0.0 --port=8000
php artisan reverb:start --host=0.0.0.0 --port=8080
php artisan schedule:work
```

El planificador ejecuta cada cinco minutos las expiraciones de pedidos pendientes y puntos, el despacho de pedidos programados y las alertas. También genera el respaldo diario de SQLite.

## Usuarios de desarrollo

En los entornos `local` y `testing`, el seeder crea estas cuentas:

| Rol | Usuario | Correo | Contraseña |
| --- | --- | --- | --- |
| Administrador | `admin` | `admin@pizzeria.local` | `Pizzeria123!` |
| Cajero | `cajero` | `cajero@pizzeria.local` | `Pizzeria123!` |
| Cocina | `cocina` | `cocina@pizzeria.local` | `Pizzeria123!` |
| Repartidor | `repartidor` | `repartidor@pizzeria.local` | `Pizzeria123!` |

El inicio de sesión acepta correo o nombre de usuario. En producción no se crean operadores de demostración y es obligatorio definir `PIZZERIA_SEED_PASSWORD` antes de ejecutar el seeder.

## Módulos y reglas principales

- Autenticación Sanctum, usuarios, roles y permisos efectivos.
- Inventario por lotes con FEFO, caducidades, alertas, ajustes y trazabilidad de movimientos.
- Compras por presentación con conversión automática a la unidad base y origen de pago.
- Producción interna con receta, consumo de insumos y lote de producto resultante.
- Productos, variantes, sabores, recetas, modificadores y paquetes configurables.
- Pedidos borrador, pendientes, confirmados y programados; pagos mixtos, cortesías y cobro contra entrega.
- Autorización explícita de faltantes, devolución antes de preparar y merma después de iniciar cocina.
- Flujos separados de caja, cocina y reparto, publicados por Reverb y respaldados por sondeo del cliente.
- Clientes con múltiples direcciones, historial, reglas simultáneas y movimientos de puntos.
- Notas de cliente, cocina y reparto en HTML, PDF o PNG según corresponda.
- Caja diaria, auditoría, reportes y operaciones automáticas programadas.

Todas las consultas operativas están limitadas a la sucursal del usuario. Aunque el despliegue inicial use una sola sucursal, las entidades transaccionales conservan `branch_id`.

## Verificación

```bash
./vendor/bin/pint --test
php artisan test
php artisan route:list --path=api
php artisan schedule:list
```

Para validar una instalación limpia sin tocar la base de datos de trabajo, usa una base SQLite temporal o ejecuta la suite, que crea su propia base mediante `RefreshDatabase`.

## Producción

- Cambia las credenciales de Reverb y no publiques `PIZZERIA_SEED_PASSWORD`.
- Define `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, CORS y los hosts reales de Reverb.
- Ejecuta `php artisan config:cache` y `php artisan route:cache` después de establecer el entorno.
- Mantén activos el servidor Reverb, el worker requerido por tu infraestructura y el cron de `php artisan schedule:run`.
- Configura respaldos nativos si se cambia SQLite por otro motor.

### Railway

El archivo `railway.json` ejecuta `railway/start.sh` en cada despliegue. El script prepara SQLite cuando no se configuró otro motor, ejecuta migraciones y seeders, almacena las cachés de Laravel y levanta la API en el puerto asignado por Railway.

Configura como mínimo estas variables en el servicio:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://tu-dominio.up.railway.app
PIZZERIA_SEED_PASSWORD=una-contraseña-segura
CORS_ALLOWED_ORIGINS=https://localhost
```

Para datos persistentes se recomienda agregar PostgreSQL y establecer `DB_CONNECTION=pgsql` y `DB_URL=${{Postgres.DATABASE_URL}}`. SQLite dentro del contenedor se reinicia con un despliegue salvo que `DB_DATABASE` apunte a un volumen persistente.
