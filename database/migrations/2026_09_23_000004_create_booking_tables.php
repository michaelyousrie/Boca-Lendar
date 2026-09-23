<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('booking_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->string('timezone');
            $table->unique(['provider', 'external_id']);
            $table->timestamps();
        });

        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('account_id');
            $table->string('email');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->json('calendars')->default('[]');
            $table->boolean('needs_reconnect')->default(false);
            $table->foreignId('selected_calendar_id')->nullable()->constrained('booking_calendars');
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('booking_calendar_id')->constrained();
            $table->foreignId('calendar_connection_id')->nullable()->constrained();
            $table->uuid('request_key')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->text('title');
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('timezone');
            $table->string('google_event_id', 1024)->nullable();
            $table->string('recurring_event_id', 1024)->nullable();
            $table->text('url')->nullable();
            $table->boolean('all_day')->default(false);
            $table->boolean('conflict_checked')->default(true);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status')->default('scheduled');
            $table->boolean('holds_slot')->default(true);
            $table->string('sync_status')->default('pending');
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->string('sync_error')->nullable();
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['sync_status', 'next_sync_at']);
            $table->index(['user_id', 'starts_at']);
            $table->unique(['calendar_connection_id', 'booking_calendar_id', 'google_event_id'], 'appointment_google_identity');
            $table->index(['calendar_connection_id', 'recurring_event_id']);
        });

        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_valid_period CHECK (ends_at > starts_at)');
        // Half-open intervals allow one appointment to start exactly when another ends.
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap EXCLUDE USING gist (booking_calendar_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (holds_slot)");

    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('calendar_connections');
        Schema::dropIfExists('booking_calendars');
    }
};
