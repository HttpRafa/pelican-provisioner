<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioner_quotas', function (Blueprint $table) {
            $table->string('egg_mode', 16)->nullable();
            $table->string('node_mode', 16)->nullable();
            $table->boolean('allow_all_eggs')->default(false);
        });

        // Existing selections were the eggs and nodes that user was limited to.
        DB::table('provisioner_quotas')->whereNotNull('allowed_egg_ids')->update(['egg_mode' => 'overwrite']);
        DB::table('provisioner_quotas')->whereNotNull('allowed_node_ids')->update(['node_mode' => 'overwrite']);
    }

    public function down(): void
    {
        Schema::table('provisioner_quotas', function (Blueprint $table) {
            $table->dropColumn(['egg_mode', 'node_mode', 'allow_all_eggs']);
        });
    }
};
