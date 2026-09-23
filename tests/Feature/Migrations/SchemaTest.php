<?php

namespace Tests\Feature\Migrations;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_clean_schema_rolls_back_and_rebuilds_with_optional_google_connections(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
        foreach (['users', 'cache', 'jobs', 'appointments', 'google_calendar_syncs'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $local = Appointment::factory()->local()->create();
        $google = Appointment::factory()->create();
        $this->assertNull($local->calendar_connection_id);
        $this->assertNotNull($google->calendar_connection_id);
        $this->assertFalse(Schema::hasTable('google_event_snapshots'));
        $this->assertFalse(Schema::hasTable('google_calendar_events'));
        $this->assertFalse(Schema::hasTable('demo_calendar_events'));
    }
}
