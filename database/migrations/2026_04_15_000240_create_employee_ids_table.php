<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('id_type_id')->constrained('id_types')->restrictOnDelete();
            $table->string('id_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ids');
    }
};
