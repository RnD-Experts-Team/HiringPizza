<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('milestone_gift_final_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_gift_request_id')->constrained('milestone_gift_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', [
                'delivered_to_employee',
                'sent_to_store_awaiting_pickup',
                'not_delivered_no_longer_with_company',
                'not_delivered_other_reason',
            ]);
            $table->string('status_other_reason')->nullable();
            $table->date('confirmation_date');
            $table->text('closing_notes')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['milestone_gift_request_id'], 'mgfs_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_gift_final_statuses');
    }
};
