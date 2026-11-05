<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 (Fees):
 *  - fee items may be scoped to a whole school (no class / session), a session, or a single class;
 *  - payments carry a free-text reference (receipt / transfer / cheque number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            $table->ulid('class_id')->nullable()->change();
            $table->ulid('session_id')->nullable()->after('class_id');
            $table->foreign('session_id')->references('id')->on('academic_sessions')->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('reference');
        });

        Schema::table('fee_items', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
            $table->dropColumn('session_id');
        });
    }
};
