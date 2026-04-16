<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_availability_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('availability_day_id')->constrained('employee_availability_days')->cascadeOnDelete();
            $table->time('available_from');
            $table->time('available_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_availability_times');
    }
};