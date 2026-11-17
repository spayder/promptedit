<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Pixelforge webhook we accept. Pixelforge delivers at least once, so the
 * unique event_id is what makes processing idempotent: a second delivery of the
 * same event cannot insert a second row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload');
            // How many times Pixelforge delivered this event (retries, [duplicate], resends).
            $table->unsignedInteger('deliveries')->default(1);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
