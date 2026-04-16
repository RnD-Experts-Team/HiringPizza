<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_maritals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emp_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('marital_id')->constrained('marital_statuses')->restrictOnDelete();
            $table->date('effective_date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_maritals');
    }
};
