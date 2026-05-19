<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('store_number')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'metric_date']);
            $table->index(['metric_date', 'store_number']);
        });

        Schema::create('employee_metric_columns', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label')->unique();
            $table->timestamps();
        });

        Schema::create('employee_metric_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_metric_id')->constrained('employee_metrics')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('employee_metric_columns')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->decimal('value_numeric', 24, 12)->nullable();
            $table->timestamps();

            $table->unique(['employee_metric_id', 'column_id']);
            $table->index(['column_id', 'value_numeric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_metric_values');
        Schema::dropIfExists('employee_metric_columns');
        Schema::dropIfExists('employee_metrics');
    }
};
