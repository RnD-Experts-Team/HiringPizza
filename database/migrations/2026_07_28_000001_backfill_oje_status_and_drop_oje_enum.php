<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $ojeRows = DB::table('employee_status_histories')
                ->where('status', 'OJE')
                ->orderBy('employee_id')
                ->orderBy('effective_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'effective_date']);

            foreach ($ojeRows as $ojeRow) {
                $nextRow = DB::table('employee_status_histories')
                    ->where('employee_id', $ojeRow->employee_id)
                    ->where(function ($q) use ($ojeRow) {
                        $q->where('effective_date', '>', $ojeRow->effective_date)
                            ->orWhere(function ($q2) use ($ojeRow) {
                                $q2->where('effective_date', '=', $ojeRow->effective_date)
                                    ->where('id', '>', $ojeRow->id);
                            });
                    })
                    ->orderBy('effective_date')
                    ->orderBy('id')
                    ->first(['id', 'status']);

                if ($nextRow !== null && $nextRow->status === 'hired') {
                    DB::table('employee_status_histories')
                        ->where('id', $nextRow->id)
                        ->update(['effective_date' => $ojeRow->effective_date]);

                    DB::table('employee_status_histories')
                        ->where('id', $ojeRow->id)
                        ->delete();
                } else {
                    DB::table('employee_status_histories')
                        ->where('id', $ojeRow->id)
                        ->update(['status' => 'hired']);
                }
            }
        });

        // MySQL implicitly commits on DDL, so this must run outside the
        // transaction above — otherwise Laravel's later explicit commit()
        // fails with "There is no active transaction".
        DB::statement("ALTER TABLE employee_status_histories MODIFY status ENUM('hired','resigned','terminated','rehired') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE employee_status_histories MODIFY status ENUM('hired','resigned','terminated','rehired','OJE') NOT NULL");
    }
};
