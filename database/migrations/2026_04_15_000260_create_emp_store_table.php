<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_store', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('store')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_store');
    }
};