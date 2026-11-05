<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year');
            $table->boolean('is_current')->default(false);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('session_id');
            $table->unsignedTinyInteger('sequence');
            $table->string('name');
            $table->unsignedSmallInteger('days_school_open')->default(0);
            $table->date('result_date')->nullable();
            $table->unsignedInteger('legacy_exam_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('academic_sessions')->cascadeOnDelete();
            $table->unique(['session_id', 'sequence']);
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('arms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->ulid('hod_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('hod_id')->references('id')->on('staff')->nullOnDelete();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->ulid('department_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('grading_scales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index('school_id');
        });

        Schema::create('grading_bands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('scale_id');
            $table->unsignedTinyInteger('min_score');
            $table->unsignedTinyInteger('max_score');
            $table->string('letter', 4);
            $table->string('remark', 40);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('scale_id')->references('id')->on('grading_scales')->cascadeOnDelete();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('session_id');
            $table->ulid('level_id');
            $table->ulid('arm_id');
            $table->ulid('form_teacher_id')->nullable();
            $table->decimal('admission_fee', 12, 2)->default(0);
            $table->decimal('hall_charge', 12, 2)->default(0);
            $table->unsignedInteger('legacy_grade_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('academic_sessions')->cascadeOnDelete();
            $table->foreign('level_id')->references('id')->on('levels')->restrictOnDelete();
            $table->foreign('arm_id')->references('id')->on('arms')->restrictOnDelete();
            $table->foreign('form_teacher_id')->references('id')->on('staff')->nullOnDelete();
            $table->unique(['session_id', 'level_id', 'arm_id']);
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('class_id');
            $table->ulid('subject_id');
            $table->ulid('teacher_id')->nullable();
            $table->string('kind')->default('COMPULSORY');
            $table->decimal('fee', 12, 2)->default(0);
            $table->unsignedInteger('legacy_routing_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('teacher_id')->references('id')->on('staff')->nullOnDelete();
            $table->unique(['class_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('grading_bands');
        Schema::dropIfExists('grading_scales');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('arms');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_sessions');
    }
};
