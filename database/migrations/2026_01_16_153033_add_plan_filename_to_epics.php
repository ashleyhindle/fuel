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
        Schema::table('epics', function (Blueprint $table) {
            $table->text('plan_filename')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn('plan_filename');
        });
    }
};
