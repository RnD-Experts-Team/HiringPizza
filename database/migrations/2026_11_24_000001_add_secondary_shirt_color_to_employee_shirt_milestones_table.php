<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_shirt_milestones', function (Blueprint $table) {
            // The second-choice colour. We never check whether a colour can
            // actually be bought — the fulfilment team does that when they
            // order — so the entry names a fallback for when the first choice
            // is not available.
            //
            // Nullable in the database because milestones submitted before
            // this column existed have none; the entry form requires it for
            // every new one (see ShirtMilestoneEntryRequest).
            $table->foreignId('secondary_shirt_color_id')
                ->nullable()
                ->after('shirt_color_id')
                ->constrained('shirt_colors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_shirt_milestones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_shirt_color_id');
        });
    }
};
