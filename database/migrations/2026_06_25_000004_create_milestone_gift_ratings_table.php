<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_gift_request_id')->constrained('milestone_gift_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('additional_comments')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->index(['milestone_gift_request_id'], 'mgrat_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_ratings');
    }
};
