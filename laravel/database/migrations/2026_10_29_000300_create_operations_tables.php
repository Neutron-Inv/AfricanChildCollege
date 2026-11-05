<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('student_id');
            $table->ulid('class_id');
            $table->ulid('session_id');
            $table->string('status')->default('ACTIVE');
            $table->unsignedInteger('legacy_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->restrictOnDelete();
            $table->foreign('session_id')->references('id')->on('academic_sessions')->cascadeOnDelete();
            $table->unique(['student_id', 'session_id']);
            $table->index('class_id');
        });

        Schema::create('enrolment_subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('enrolment_id');
            $table->ulid('class_subject_id');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('enrolment_id')->references('id')->on('enrolments')->cascadeOnDelete();
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->unique(['enrolment_id', 'class_subject_id']);
        });

        Schema::create('subject_scores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('enrolment_id');
            $table->ulid('class_subject_id');
            $table->ulid('term_id');
            $table->decimal('ca1', 6, 2)->default(0);
            $table->decimal('ca2', 6, 2)->default(0);
            $table->decimal('ca3', 6, 2)->default(0);
            $table->decimal('exam_score', 6, 2)->default(0);
            $table->boolean('absent')->default(false);
            $table->unsignedInteger('legacy_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('enrolment_id')->references('id')->on('enrolments')->cascadeOnDelete();
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->foreign('term_id')->references('id')->on('terms')->cascadeOnDelete();
            $table->unique(['enrolment_id', 'class_subject_id', 'term_id']);
        });

        // Generated total column for MySQL
        DB::statement('ALTER TABLE subject_scores ADD COLUMN total DECIMAL(6,2) GENERATED ALWAYS AS (ca1 + ca2 + ca3 + exam_score) STORED');

        Schema::create('term_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('enrolment_id');
            $table->ulid('term_id');
            $table->unsignedSmallInteger('days_present')->default(0);
            $table->unsignedSmallInteger('days_absent')->default(0);
            $table->string('teacher_comment')->default('');
            $table->string('principal_comment')->default('');
            $table->unsignedTinyInteger('attendance')->nullable();
            $table->unsignedTinyInteger('honesty')->nullable();
            $table->unsignedTinyInteger('neatness')->nullable();
            $table->unsignedTinyInteger('attentiveness')->nullable();
            $table->unsignedTinyInteger('dexterity')->nullable();
            $table->unsignedTinyInteger('handwriting')->nullable();
            $table->unsignedTinyInteger('verbal')->nullable();
            $table->unsignedTinyInteger('sports')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('enrolment_id')->references('id')->on('enrolments')->cascadeOnDelete();
            $table->foreign('term_id')->references('id')->on('terms')->cascadeOnDelete();
            $table->unique(['enrolment_id', 'term_id']);
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('class_subject_id');
            $table->ulid('room_id')->nullable();
            $table->string('day');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('student_id')->nullable();
            $table->ulid('staff_id')->nullable();
            $table->date('date');
            $table->string('status');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
            $table->index(['school_id', 'date']);
        });

        Schema::create('fee_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('class_id');
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->unique(['class_id', 'name']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('student_id');
            $table->ulid('term_id');
            $table->ulid('class_id');
            $table->string('status')->default('ISSUED');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('term_id')->references('id')->on('terms')->restrictOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->restrictOnDelete();
            $table->unique(['student_id', 'term_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('invoice_id');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('invoice_id');
            $table->decimal('amount', 12, 2);
            $table->string('method');
            $table->date('paid_on');
            $table->string('note')->default('');
            $table->ulid('received_by_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('class_subject_id');
            $table->ulid('term_id');
            $table->string('week')->default('');
            $table->date('lesson_date')->nullable();
            $table->string('time')->default('');
            $table->string('duration')->default('');
            $table->string('period')->default('');
            $table->string('topic');
            $table->string('sub_topic')->default('');
            $table->text('specific_objective')->nullable();
            $table->text('skills')->nullable();
            $table->text('previous_knowledge')->nullable();
            $table->text('materials')->nullable();
            $table->text('exploration')->nullable();
            $table->text('step1')->nullable();
            $table->text('step2')->nullable();
            $table->text('step3')->nullable();
            $table->text('step4')->nullable();
            $table->text('application')->nullable();
            $table->text('evaluation')->nullable();
            $table->text('conclusion')->nullable();
            $table->text('assignment')->nullable();
            $table->text('reference')->nullable();
            $table->ulid('attachment_file_id')->nullable();
            $table->ulid('author_id')->nullable();
            $table->ulid('hod_id')->nullable();
            $table->timestamp('hod_signed_at')->nullable();
            $table->timestamps();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->foreign('term_id')->references('id')->on('terms')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('hod_id')->references('id')->on('staff')->nullOnDelete();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('class_subject_id');
            $table->ulid('term_id');
            $table->string('title');
            $table->text('note')->nullable();
            $table->date('due_date')->nullable();
            $table->ulid('file_id')->nullable();
            $table->ulid('teacher_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->cascadeOnDelete();
            $table->foreign('term_id')->references('id')->on('terms')->cascadeOnDelete();
            $table->foreign('teacher_id')->references('id')->on('staff')->nullOnDelete();
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('title');
            $table->text('body');
            $table->string('audience')->nullable();
            $table->ulid('created_by_id');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        Schema::create('school_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index(['school_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_events');
        Schema::dropIfExists('notices');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('lesson_plans');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_items');
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('term_reports');
        Schema::dropIfExists('subject_scores');
        Schema::dropIfExists('enrolment_subjects');
        Schema::dropIfExists('enrolments');
    }
};
