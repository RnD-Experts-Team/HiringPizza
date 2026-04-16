<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_financial_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('account_number');
            $table->text('routing_number');
            $table->enum('account_type', ['checking', 'savings']);
            $table->date('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_financial_info');
    }
};