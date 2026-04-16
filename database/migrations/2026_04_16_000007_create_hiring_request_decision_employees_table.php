<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hiring_request_decision_employees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hiring_request_decision_id')
                ->constrained('hiring_request_decisions', 'id', 'hrde_hrd_id_fk')
                ->cascadeOnDelete();

            $table->foreignId('employee_id')
                ->constrained('employees', 'id', 'hrde_emp_id_fk')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['hiring_request_decision_id', 'employee_id'], 'hrde_unique');
            $table->index('employee_id', 'hrde_emp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_request_decision_employees');
    }
};
