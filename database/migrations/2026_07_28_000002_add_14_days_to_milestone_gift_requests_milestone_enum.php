<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE milestone_gift_requests MODIFY milestone ENUM('8_days','14_days','1_month','2_months','3_months','4_months','5_months','6_months','8_months','1_year','other') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE milestone_gift_requests MODIFY milestone ENUM('8_days','1_month','2_months','3_months','4_months','5_months','6_months','8_months','1_year','other') NOT NULL");
    }
};
