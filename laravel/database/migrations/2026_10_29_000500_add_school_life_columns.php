<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->string('status')->default('DRAFT')->after('term_id');
            $table->text('content')->nullable()->after('specific_objective');
            $table->timestamp('submitted_at')->nullable()->after('hod_signed_at');
        });

        // Lesson plans imported before this column existed: signed ones are approved.
        DB::table('lesson_plans')->whereNotNull('hod_signed_at')->update(['status' => 'APPROVED']);

        Schema::table('notices', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable()->after('audience');
        });

        Schema::table('school_events', function (Blueprint $table) {
            $table->boolean('all_day')->default(false)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('school_events', fn (Blueprint $table) => $table->dropColumn('all_day'));
        Schema::table('notices', fn (Blueprint $table) => $table->dropColumn('publish_at'));
        Schema::table('lesson_plans', fn (Blueprint $table) => $table->dropColumn(['status', 'content', 'submitted_at']));
    }
};
