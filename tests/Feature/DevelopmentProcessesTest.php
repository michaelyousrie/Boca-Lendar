<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DevelopmentProcessesTest extends TestCase
{
    public function test_dev_includes_the_fixed_port_server_and_sync_processes(): void
    {
        Artisan::call('dev:list', ['--json' => true]);
        $processes = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->pluck('command', 'name');

        $this->assertSame('php artisan serve --host=127.0.0.1 --port=2017', $processes->get('server'));
        $this->assertSame('php artisan queue:listen --tries=1 --timeout=90', $processes->get('queue'));
        $this->assertSame('php artisan schedule:work', $processes->get('schedule'));
        $this->assertSame('npm run dev', $processes->get('vite'));
    }
}
