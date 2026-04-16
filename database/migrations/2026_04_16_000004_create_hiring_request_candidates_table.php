<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hiring_request_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hiring_request_id')->constrained('hiring_requests')->onDelete('cascade');
            $table->string('name');
            $table->string('phone');
            $table->string('email');
            $table->timestamps();

            $table->index('hiring_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_request_candidates');
    }
};
