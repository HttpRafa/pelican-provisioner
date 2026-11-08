<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Own tables on purpose. Altering users or servers would rewrite every row on a large panel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioner_quotas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedInteger('cpu');
            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('server_limit')->nullable();
            $table->string('egg_mode', 16)->nullable();
            $table->string('node_mode', 16)->nullable();
            $table->boolean('allow_all_eggs')->default(false);
            $table->json('allowed_egg_ids')->nullable();
            $table->json('allowed_node_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('provisioner_quota_bans', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioner_quota_bans');
        Schema::dropIfExists('provisioner_quotas');
    }
};
