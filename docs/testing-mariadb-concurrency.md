# Prueba de concurrencia de consecutivos en MariaDB

La prueba `MariaDbOrderConcurrencyTest` se omite deliberadamente con SQLite. Usa dos procesos PHP y dos conexiones independientes para crear pedidos al mismo tiempo contra una tabla InnoDB.

Utiliza exclusivamente una base de datos desechable: la prueba ejecuta `migrate:fresh`.

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3306 \
DB_DATABASE=pizzeria_testing \
DB_USERNAME=pizzeria_test \
DB_PASSWORD='test-password' \
php artisan test tests/Feature/MariaDbOrderConcurrencyTest.php
```

Requisitos:

- MariaDB accesible con una base dedicada a pruebas.
- Tablas con motor InnoDB.
- Extensión PHP `pcntl` habilitada.
- El usuario de pruebas debe poder crear y eliminar tablas dentro de esa base.

El resultado esperado es que los dos procesos obtengan exactamente los números `1` y `2`, nunca el mismo número.
