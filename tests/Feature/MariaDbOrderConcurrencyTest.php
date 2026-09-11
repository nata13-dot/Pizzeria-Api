<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MariaDbOrderConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_creations_never_receive_the_same_daily_number(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a dedicated MariaDB/InnoDB test database.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the PHP pcntl extension.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed();
        $user = User::firstOrFail();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create([
            'branch_id' => $user->branch_id,
            'base_unit_id' => $unit->id,
            'name' => 'Queso concurrencia',
        ]);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Pizza concurrencia', 'type' => 'pizza']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Grande', 'price' => 200]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Receta concurrencia']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'component' => 'base']);

        $directory = sys_get_temp_dir().'/pizzeria-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $startFile = $directory.'/start';
        $children = [];

        DB::disconnect();
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (! file_exists($startFile)) {
                    usleep(1000);
                }
                try {
                    DB::purge();
                    $order = app(OrderService::class)->create([
                        'status' => 'pending_payment',
                        'type' => 'pickup',
                        'contact_name' => 'Proceso '.$worker,
                        'contact_phone' => '5551234567',
                        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
                        'payments' => [],
                        'idempotency_key' => 'mariadb-concurrency-'.$worker,
                    ], User::findOrFail($user->id));
                    file_put_contents($directory.'/result-'.$worker, (string) $order->daily_number);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($directory.'/error-'.$worker, (string) $exception);
                    exit(1);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }

        touch($startFile);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, $this->childErrors($directory));
        }

        $numbers = [(int) file_get_contents($directory.'/result-1'), (int) file_get_contents($directory.'/result-2')];
        sort($numbers);
        $this->assertSame([1, 2], $numbers);
        $this->assertSame(2, count(array_unique($numbers)));

        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    private function childErrors(string $directory): string
    {
        return collect(glob($directory.'/error-*'))
            ->map(fn (string $file) => file_get_contents($file))
            ->implode("\n");
    }
}
