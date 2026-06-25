<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_gift_question_id')->constrained('milestone_gift_questions')->onDelete('cascade');
            $table->string('option_text');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['milestone_gift_question_id', 'sort_order'], 'mgqo_question_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_question_options');
    }
};
