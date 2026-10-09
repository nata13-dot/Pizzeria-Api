<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CustomerController;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductConfigurationOperation;
use App\Models\User;
use App\Services\ProductConfiguration;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MariaDbConfigurationConcurrencyTest extends TestCase
{
    public function test_concurrent_edits_conflict_and_default_address_changes_serialize(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a dedicated MariaDB/InnoDB database and pcntl.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Concurrente', 'type' => 'other']);
        $variant = $product->variants()->create(['name' => 'Unidad', 'price' => 100]);
        $customer = Customer::create(['branch_id' => $user->branch_id, 'name' => 'Ana', 'phone' => '123']);
        $addresses = [];
        foreach ([1, 2] as $i) {
            $addresses[$i] = $customer->addresses()->create(['label' => 'Dirección '.$i, 'address' => 'Centro', 'is_default' => $i === 1])->id;
        }
        $request = Request::create('/configuration', 'GET');
        $request->setUserResolver(fn () => $user);
        $revision = app(ProductConfiguration::class)->read($request, $product)['revision'];
        $directory = sys_get_temp_dir().'/pizzeria-config-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    DB::purge();
                    $actor = User::findOrFail($user->id);
                    $request = Request::create('/configuration', 'PUT', [
                        'expected_revision' => $revision, 'operation_key' => 'concurrent-config-'.$worker,
                        'product' => ['name' => 'Cambio '.$worker], 'variants' => [['id' => $variant->id, 'price' => 100 + $worker]],
                        'flavors' => [['name' => 'Sabor '.$worker]], 'removed_variant_ids' => [], 'removed_flavor_ids' => [],
                    ]);
                    $request->setUserResolver(fn () => $actor);
                    try {
                        app(ProductConfiguration::class)->save($request, Product::findOrFail($product->id));
                        $result = 'saved';
                    } catch (HttpResponseException $error) {
                        if ($error->getResponse()->getStatusCode() !== 409) {
                            throw $error;
                        }
                        $result = 'conflict';
                    }
                    $addressRequest = Request::create('/address', 'PUT', ['is_default' => true]);
                    $addressRequest->setUserResolver(fn () => $actor);
                    app(CustomerController::class)->updateAddress($addressRequest, Customer::findOrFail($customer->id), Customer::findOrFail($customer->id)->addresses()->findOrFail($addresses[$worker]));
                    file_put_contents($directory.'/result-'.$worker, $result);
                    exit(0);
                } catch (\Throwable $error) {
                    file_put_contents($directory.'/error-'.$worker, (string) $error);
                    exit(1);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        touch($directory.'/start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        $results = [file_get_contents($directory.'/result-1'), file_get_contents($directory.'/result-2')];
        sort($results);
        $this->assertSame(['conflict', 'saved'], $results);
        $this->assertSame(1, ProductConfigurationOperation::count());
        $this->assertSame(1, $product->flavors()->count());
        $this->assertSame(1, $customer->addresses()->where('is_default', true)->count());
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
