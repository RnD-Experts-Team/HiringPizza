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
            $table->enum('milestone', ['30_days', '90_days', '6_months', '1_year', '2_years', 'other']);
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
