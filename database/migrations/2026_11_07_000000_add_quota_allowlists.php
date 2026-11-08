<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioner_quotas', function (Blueprint $table) {
            $table->json('allowed_egg_ids')->nullable();
            $table->json('allowed_node_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provisioner_quotas', function (Blueprint $table) {
            $table->dropColumn(['allowed_egg_ids', 'allowed_node_ids']);
        });
    }
};
