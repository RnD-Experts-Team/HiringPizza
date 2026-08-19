<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local mirror of TCP's job-code catalog, synced by `tcp:sync-job-codes`.
 *
 * Employee-create used to resolve defaultJobCode by hitting TCP's live
 * /jobcodes endpoint and caching the result for an hour. Any mismatch just
 * omitted the field from the payload, and TCP rejected the create with an
 * opaque "The cell must have a value" — this table lets that be caught
 * locally instead, mirroring OperationsPizza's tcp_job_codes.
 *
 * Job codes are per-store ("Crew Member - 3795-01") with a "Restaurant Id"
 * custom field carrying the store_number — that field, not the description,
 * is what attributes a code to a store.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tcp_job_codes', function (Blueprint $table) {
            $table->id();

            // TCP's jobCodeId (e.g. 37950101) — the value a create/punch carries.
            $table->string('tcp_job_code_id', 64)->unique();

            $table->string('description', 190);

            // From the "Restaurant Id" custom field. NULL = a company-wide
            // code, present = a per-store code.
            $table->string('store_number', 32)->nullable()->index();

            $table->boolean('clockable')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_job_codes');
    }
};
