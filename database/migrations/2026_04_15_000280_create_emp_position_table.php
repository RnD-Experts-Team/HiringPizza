<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_position', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_position');
    }
};