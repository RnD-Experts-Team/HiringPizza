<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One store's total payroll and payroll-to-sales % for one Tue–Mon business
 * week, for the weeks before per-employee metrics were imported. A store-week
 * with no metrics returns these as its gross pay and final labor; weeks with
 * metrics never read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payroll_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->decimal('total_payroll', 12, 2)->nullable();
            // The sheet's Payroll to Sales, as a percent (0.2216 → 22.16).
            $table->decimal('labor_percent', 8, 4)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'week_start']);
            $table->index('week_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payroll_weeks');
    }
};
