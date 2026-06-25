<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_rating_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_gift_rating_id');
            $table->unsignedBigInteger('milestone_gift_question_id');
            $table->timestamps();

            $table->foreign('milestone_gift_rating_id', 'mgra_rating_fk')
                ->references('id')
                ->on('milestone_gift_ratings')
                ->onDelete('cascade');

            $table->foreign('milestone_gift_question_id', 'mgra_question_fk')
                ->references('id')
                ->on('milestone_gift_questions')
                ->onDelete('cascade');

            $table->index(['milestone_gift_rating_id'], 'mgra_rating_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_rating_answers');
    }
};
