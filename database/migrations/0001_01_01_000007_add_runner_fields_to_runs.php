<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->integer('pid')->nullable();
            $table->text('runner_instance_id')->nullable();

            $table->index('pid', 'idx_runs_pid');
            $table->index('runner_instance_id', 'idx_runs_runner_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex('idx_runs_pid');
            $table->dropIndex('idx_runs_runner_instance_id');
            $table->dropColumn(['pid', 'runner_instance_id']);
        });
    }
};
