<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'consume_pid')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('consume_pid');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tasks', 'consume_pid')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->integer('consume_pid')->nullable();
            });
        }
    }
};
