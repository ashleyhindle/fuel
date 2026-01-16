<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->text('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
