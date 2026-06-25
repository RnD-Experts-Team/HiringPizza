<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_rating_answer_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_gift_rating_answer_id');
            $table->unsignedBigInteger('milestone_gift_question_option_id');
            $table->timestamps();

            $table->foreign('milestone_gift_rating_answer_id', 'mgrao_answer_fk')
                ->references('id')
                ->on('milestone_gift_rating_answers')
                ->onDelete('cascade');

            $table->foreign('milestone_gift_question_option_id', 'mgrao_option_fk')
                ->references('id')
                ->on('milestone_gift_question_options')
                ->onDelete('cascade');

            $table->index(['milestone_gift_rating_answer_id'], 'mgrao_answer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_rating_answer_options');
    }
};
