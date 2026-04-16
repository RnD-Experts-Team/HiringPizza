<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hiring_request_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hiring_request_id')->constrained('hiring_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('number_hired');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['hiring_request_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_request_decisions');
    }
};
