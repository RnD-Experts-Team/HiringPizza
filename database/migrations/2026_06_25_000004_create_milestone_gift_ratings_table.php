<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_gift_request_id');
            $table->unsignedBigInteger('user_id');
            $table->text('additional_comments')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->foreign('milestone_gift_request_id', 'mgrat_request_fk')
                ->references('id')
                ->on('milestone_gift_requests')
                ->onDelete('cascade');

            $table->foreign('user_id', 'mgrat_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['milestone_gift_request_id'], 'mgrat_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_ratings');
    }
};
