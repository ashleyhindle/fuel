<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_health', function (Blueprint $table) {
            $table->text('agent')->primary();
            $table->text('last_success_at')->nullable();
            $table->text('last_failure_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->text('backoff_until')->nullable();
            $table->integer('total_runs')->default(0);
            $table->integer('total_successes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_health');
    }
};
