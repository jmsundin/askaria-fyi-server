<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('call_sid', 64)->nullable()->unique();
            $table->string('session_id', 64)->nullable()->index();
            $table->string('from_number', 32)->nullable()->index();
            $table->string('to_number', 32)->nullable()->index();
            $table->string('forwarded_from', 32)->nullable();
            $table->string('caller_name', 120)->nullable();
            $table->string('status', 32)->default('in_progress');
            $table->boolean('is_starred')->default(false);
            $table->string('recording_url')->nullable();
            $table->json('summary')->nullable();
            $table->json('transcript_messages')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};

