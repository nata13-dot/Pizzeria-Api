<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SanitizeOrderDailyNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_previews_trace_without_modifying_orders_by_default(): void
    {
        Storage::fake('local');
        $this->seed();
        $user = User::firstOrFail();
        foreach ([2, 2, 3, 3] as $index => $number) {
            Order::create([
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'order_date' => '2026-09-05',
                'daily_number' => $number,
                'status' => $index % 2 ? 'cancelled' : 'confirmed',
                'type' => 'pickup',
            ]);
        }

        $before = Order::orderBy('id')->pluck('daily_number', 'id')->all();

        $this->artisan('orders:sanitize-daily-numbers', ['--trace' => 'daily-number-test.csv'])
            ->expectsOutput('Trace written to storage/app/private/daily-number-test.csv')
            ->expectsOutput('Preview only: no database rows were modified.')
            ->assertSuccessful();

        $this->assertSame($before, Order::orderBy('id')->pluck('daily_number', 'id')->all());
        Storage::disk('local')->assertExists('daily-number-test.csv');
        $trace = Storage::disk('local')->get('daily-number-test.csv');
        $this->assertStringContainsString('order_id,branch_id,order_date,old_daily_number,new_daily_number', $trace);
        $this->assertStringContainsString(',2,4', $trace);
        $this->assertStringContainsString(',3,5', $trace);
    }
}
