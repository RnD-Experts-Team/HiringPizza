<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_gift_request_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_cancelled')->default(false);
            $table->text('cancellation_reason')->nullable();
            $table->string('gift_description')->nullable();
            $table->decimal('gift_cost', 10, 2)->nullable();
            $table->date('delivery_date')->nullable();
            $table->boolean('sent_to_store')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->foreign('milestone_gift_request_id', 'mgd_request_fk')
                ->references('id')
                ->on('milestone_gift_requests')
                ->onDelete('cascade');

            $table->foreign('user_id', 'mgd_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['milestone_gift_request_id'], 'mgd_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_decisions');
    }
};
