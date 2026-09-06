<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupController extends Controller
{
    public function create(): JsonResponse
    {
        return response()->json([
            'download_url' => URL::temporarySignedRoute('database-backups.download', now()->addMinutes(5)),
            'expires_in_minutes' => 5,
        ]);
    }

    public function download(): StreamedResponse
    {
        $driver = DB::connection()->getDriverName();
        $tables = $this->tables($driver);
        $filename = 'respaldo-pizzeria-'.now()->format('Y-m-d_H-i-s').'.json';

        return response()->streamDownload(function () use ($driver, $tables): void {
            echo json_encode([
                'format' => 'pizzeria-database-backup-v1',
                'generated_at' => now()->toIso8601String(),
                'database_driver' => $driver,
                'tables' => collect($tables)->mapWithKeys(
                    fn (string $table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()],
                )->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    private function tables(string $driver): array
    {
        $tables = match ($driver) {
            'pgsql' => DB::select('select tablename as name from pg_tables where schemaname = current_schema() order by tablename'),
            'mysql', 'mariadb' => DB::select('select table_name as name from information_schema.tables where table_schema = database() order by table_name'),
            'sqlite' => DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"),
            default => throw new \RuntimeException("El motor de base de datos {$driver} no admite respaldos."),
        };

        return array_values(array_map(fn ($table) => (string) $table->name, $tables));
    }
}
