<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emp_obsession', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->enum('t_shirt', ['L', 'M', 'S', 'XL', 'XS', '2XL', '3XL', '4XL', '5XL', '6XL'])->nullable();
            $table->date('birth_date');
            $table->string('image_path')->nullable();
            $table->enum('religion', ['Christianity', 'Islam', 'Judaism', 'Buddhism', 'Hinduism', 'Other'])->nullable();
            $table->enum('race', ['Caucasian', 'African American', 'Hispanic', 'Asian', 'Native American', 'Other'])->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_obsession');
    }
};