<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->text('mirror_path')->nullable();
            $table->text('mirror_status')->default('none');
            $table->text('mirror_branch')->nullable();
            $table->text('mirror_base_commit')->nullable();
            $table->dateTime('mirror_created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn([
                'mirror_path',
                'mirror_status',
                'mirror_branch',
                'mirror_base_commit',
                'mirror_created_at',
            ]);
        });
    }
};
