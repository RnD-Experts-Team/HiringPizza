<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One external id per (employee, id type). Application code always went
 * through updateOrCreate, so duplicates could only appear via raw writes —
 * but external ids are the employee↔TCP↔Humanity link, and a duplicate "TCP ID"
 * row would make which-person-is-this ambiguous. The constraint makes the
 * invariant structural.
 */
return new class extends Migration {
    public function up(): void
    {
        // Dedupe first or the index cannot build: keep the newest row (highest
        // id) for each (employee_id, id_type_id) pair.
        $keep = DB::table('employee_ids')
            ->selectRaw('MAX(id) as id')
            ->groupBy('employee_id', 'id_type_id')
            ->pluck('id');

        DB::table('employee_ids')->whereNotIn('id', $keep)->delete();

        Schema::table('employee_ids', function (Blueprint $table) {
            $table->unique(['employee_id', 'id_type_id']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_ids', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'id_type_id']);
        });
    }
};
