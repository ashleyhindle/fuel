<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->increments('id');
            $table->text('short_id');
            $table->foreignId('task_id')->nullable();
            $table->text('agent')->nullable();
            $table->text('status')->default('pending');
            $table->text('issues')->nullable();
            $table->text('started_at')->nullable();
            $table->text('completed_at')->nullable();
            $table->unsignedInteger('run_id')->nullable();

            $table->unique('short_id');
            $table->index('short_id', 'idx_reviews_short_id');
            $table->index('task_id', 'idx_reviews_task_id');
            $table->index('status', 'idx_reviews_status');
            $table->index('run_id', 'idx_reviews_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
