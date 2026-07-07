<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->enum('milestone', ['8_days', '1_month', '2_months', '3_months', '4_months', '5_months', '6_months', '8_months', '1_year', 'other']);
            $table->string('milestone_other')->nullable();
            $table->enum('stage', ['created', 'rating', 'gift_decision', 'final_status', 'closed', 'cancelled'])->default('created');
            $table->timestamps();

            $table->index(['store_id', 'created_at'], 'mgr_store_created_at_index');
            $table->index(['employee_id', 'created_at'], 'mgr_employee_created_at_index');
            $table->index(['store_id', 'stage'], 'mgr_store_stage_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_requests');
    }
};
