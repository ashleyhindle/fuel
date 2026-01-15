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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('agent')->nullable()->after('complexity');
            $table->integer('selfguided_iteration')->default(0)->after('agent');
            $table->integer('selfguided_stuck_count')->default(0)->after('selfguided_iteration');
        });

        Schema::table('epics', function (Blueprint $table) {
            $table->boolean('self_guided')->default(false)->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['agent', 'selfguided_iteration', 'selfguided_stuck_count']);
        });

        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn('self_guided');
        });
    }
};
