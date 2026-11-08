<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Own tables on purpose. Altering users or servers would rewrite every row on a large panel.
// The panel's Import button installs over an older version without rolling it back, and its older migration files are gone by then. A plain create would fail on every retry, and the panel refuses to uninstall a plugin it marks as not installed, so existing tables are kept and completed instead.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provisioner_quotas')) {
            $this->addMissingQuotaColumns();
        } else {
            $this->createQuotas();
        }

        if (!Schema::hasTable('provisioner_quota_bans')) {
            $this->createBans();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioner_quota_bans');
        Schema::dropIfExists('provisioner_quotas');
    }

    private function createQuotas(): void
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
    }

    private function addMissingQuotaColumns(): void
    {
        $columns = [
            'egg_mode' => fn (Blueprint $table) => $table->string('egg_mode', 16)->nullable(),
            'node_mode' => fn (Blueprint $table) => $table->string('node_mode', 16)->nullable(),
            'allow_all_eggs' => fn (Blueprint $table) => $table->boolean('allow_all_eggs')->default(false),
            'allowed_egg_ids' => fn (Blueprint $table) => $table->json('allowed_egg_ids')->nullable(),
            'allowed_node_ids' => fn (Blueprint $table) => $table->json('allowed_node_ids')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('provisioner_quotas', $name)) {
                Schema::table('provisioner_quotas', $add);
            }
        }
    }

    private function createBans(): void
    {
        Schema::create('provisioner_quota_bans', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }
};
