<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student who has finished school is still off the active roll, but departure
 * records whether they left or graduated. Enrolment outcome records the same
 * choice for that session's placement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('departure', 20)->nullable()->after('status');
        });

        Schema::table('enrolments', function (Blueprint $table) {
            $table->string('outcome', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('departure');
        });
    }
};
