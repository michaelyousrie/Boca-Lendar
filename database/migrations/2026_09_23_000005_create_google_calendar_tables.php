<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->string('calendar_id', 1024);
            $table->string('timezone');
            $table->unsignedSmallInteger('import_year')->nullable();
            $table->text('sync_token')->nullable();
            $table->uuid('request_token')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['calendar_connection_id', 'calendar_id'], 'google_calendar_sync_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_syncs');
    }
};
