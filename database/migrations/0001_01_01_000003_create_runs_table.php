<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->increments('id');
            $table->text('short_id');
            $table->foreignId('task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->text('agent');
            $table->text('status')->default('running');
            $table->integer('exit_code')->nullable();
            $table->text('started_at')->nullable();
            $table->text('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->text('session_id')->nullable();
            $table->text('error_type')->nullable();
            $table->text('model')->nullable();
            $table->text('output')->nullable();
            $table->double('cost_usd')->nullable();

            $table->unique('short_id');
            $table->index('short_id', 'idx_runs_short_id');
            $table->index('task_id', 'idx_runs_task_id');
            $table->index('agent', 'idx_runs_agent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
