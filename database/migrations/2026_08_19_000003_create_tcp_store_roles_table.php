<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local mirror of "which TCP role belongs to which store", synced by
 * `tcp:sync-role-map` and/or set by hand with `tcp:map-role`.
 *
 * TCP's employee `roleId` is a plain string, and on this account the only
 * valid values are US state postal codes (config('tcp.valid_role_ids')) — TCP
 * itself does not derive it from `location`/store, so the mapping has to live
 * somewhere. `tcp:sync-role-map` derives it for free from `roleId`s already
 * set by hand in TCP's UI on existing employees, grouped by their `location`;
 * `tcp:map-role` covers stores with no employees yet to observe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tcp_store_roles', function (Blueprint $table) {
            $table->id();

            $table->string('store_number', 32)->unique();
            $table->string('role_id', 32);

            // 'observed' = derived from existing employees' roleId by
            // tcp:sync-role-map; 'manual' = set directly by tcp:map-role.
            // Sync never overwrites a manual row silently — see the command.
            $table->string('source', 16)->default('manual');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_store_roles');
    }
};
