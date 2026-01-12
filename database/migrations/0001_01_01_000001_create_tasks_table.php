<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->text('short_id');
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('open');
            $table->text('type')->default('task');
            $table->integer('priority')->default(2);
            $table->text('complexity')->default('moderate');
            $table->text('labels')->nullable();
            $table->text('blocked_by')->nullable();
            $table->foreignId('epic_id')->nullable()->constrained()->nullOnDelete();
            $table->text('commit_hash')->nullable();
            $table->text('reason')->nullable();
            $table->integer('consumed')->default(0);
            $table->text('consumed_at')->nullable();
            $table->integer('consumed_exit_code')->nullable();
            $table->text('consumed_output')->nullable();
            $table->integer('consume_pid')->nullable();
            $table->text('last_review_issues')->nullable();
            $table->text('created_at')->nullable();
            $table->text('updated_at')->nullable();

            $table->unique('short_id');
            $table->index('status', 'idx_tasks_status');
            $table->index('epic_id', 'idx_tasks_epic_id');
            $table->index('short_id', 'idx_tasks_short_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
