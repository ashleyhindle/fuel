<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('status', 'closed')
            ->update(['status' => 'done']);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->where('status', 'done')
            ->update(['status' => 'closed']);
    }
};
