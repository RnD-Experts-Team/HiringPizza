<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('separation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->enum('separation_type', ['termination', 'resignation']);
            $table->date('final_working_day');
            $table->longText('termination_letter')->nullable();
            $table->enum('termination_reason', [
                'performance_issues',
                'policy_violation_misconduct',
                'attendance_issues',
                'no_call_no_show_more_than_2_times_job_abandonment',
                'end_of_trial_period',
                'reach_the_limits_of_caps_needed',
                'other'
            ])->nullable();
            $table->text('termination_reason_details')->nullable();
            $table->enum('resignation_reason', [
                'found_another_job',
                'school_schedule_conflict',
                'relocation',
                'personal_reasons',
                'health_family_reasons',
                'cognito_form',
                'other'
            ])->nullable();
            $table->text('resignation_reason_details')->nullable();
            $table->text('additional_notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separation_requests');
    }
};
