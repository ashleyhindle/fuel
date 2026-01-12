<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epics', function (Blueprint $table) {
            $table->increments('id');
            $table->text('short_id');
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('planning');
            $table->text('reviewed_at')->nullable();
            $table->text('approved_at')->nullable();
            $table->text('approved_by')->nullable();
            $table->text('changes_requested_at')->nullable();
            $table->text('created_at')->nullable();
            $table->text('updated_at')->nullable();

            $table->unique('short_id');
            $table->index('short_id', 'idx_epics_short_id');
            $table->index('status', 'idx_epics_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epics');
    }
};
