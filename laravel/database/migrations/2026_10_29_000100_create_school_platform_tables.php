<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->unique();
            $table->string('address')->default('');
            $table->string('email')->default('');
            $table->string('phone')->default('');
            $table->string('motto')->default('');
            $table->string('primary_color', 7)->default('#a01010');
            $table->ulid('logo_file_id')->nullable();
            $table->timestamps();
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('purpose');
            $table->string('storage_key');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->string('original_name');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index('school_id');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->foreign('logo_file_id')->references('id')->on('stored_files')->nullOnDelete();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('index_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender')->default('');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('address')->default('');
            $table->ulid('photo_file_id')->nullable();
            $table->boolean('is_teacher')->default(false);
            $table->string('status')->default('ACTIVE');
            $table->unsignedInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'index_number']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('index_number');
            $table->string('full_name');
            $table->string('gender')->default('');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('address')->default('');
            $table->date('birth_date')->nullable();
            $table->ulid('photo_file_id')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->unsignedInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'index_number']);
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('full_name');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('address')->default('');
            $table->string('gender')->default('');
            $table->string('legacy_index')->nullable();
            $table->timestamps();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index('school_id');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id')->nullable();
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->boolean('must_change_password')->default(false);
            $table->ulid('staff_id')->nullable()->unique();
            $table->ulid('student_id')->nullable()->unique();
            $table->ulid('guardian_id')->nullable()->unique();
            $table->rememberToken();
            $table->timestamps();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('guardian_id')->references('id')->on('guardians')->cascadeOnDelete();
            $table->unique(['school_id', 'email']);
            $table->index('email');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->string('entity_id')->nullable();
            $table->string('request_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('school_id')->references('id')->on('schools')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['school_id', 'created_at']);
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('student_id');
            $table->ulid('guardian_id');
            $table->string('relationship')->default('parent');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('guardian_id')->references('id')->on('guardians')->cascadeOnDelete();
            $table->unique(['student_id', 'guardian_id']);
        });

        Schema::create('legacy_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('entity');
            $table->string('legacy_key');
            $table->string('record_id');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'entity', 'legacy_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_links');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('users');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('students');
        Schema::dropIfExists('staff');
        Schema::table('schools', function (Blueprint $table) {
            $table->dropForeign(['logo_file_id']);
        });
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('schools');
    }
};
