<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_attachement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->constrained('attachements_types')->restrictOnDelete();
            $table->foreignId('emp_id')->constrained('employees')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_attachement');
    }
};
