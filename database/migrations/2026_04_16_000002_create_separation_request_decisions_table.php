<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('separation_request_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('separation_request_id')->constrained('separation_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('decision', ['rejected', 'completed']);
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['separation_request_id', 'created_at'], 'separation_decision_request_id_created_at_index');
            $table->index(['user_id', 'created_at'], 'separation_decision_user_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separation_request_decisions');
    }
};
