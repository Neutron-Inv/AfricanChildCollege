<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school day: ordered periods and breaks shared by every class.
 * WEEK covers Monday–Friday, unless FRIDAY rows exist, in which case WEEK stops on Thursday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('day_group', 10);
            $table->unsignedTinyInteger('sequence');
            $table->string('kind', 10);
            $table->string('label', 40);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'day_group', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
