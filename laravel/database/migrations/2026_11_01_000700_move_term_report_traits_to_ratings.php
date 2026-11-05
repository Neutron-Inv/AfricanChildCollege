<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Behaviour scores used to be columns on term_reports (the legacy student_exam shape).
 * Each score is now its own row, so a quality that was not rated simply has no row.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $traits = [
        'attendance',
        'honesty',
        'neatness',
        'attentiveness',
        'dexterity',
        'handwriting',
        'verbal',
        'sports',
    ];

    public function up(): void
    {
        Schema::create('term_report_ratings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('term_report_id');
            $table->string('trait', 32);
            $table->unsignedTinyInteger('score');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('term_report_id')->references('id')->on('term_reports')->cascadeOnDelete();
            $table->unique(['term_report_id', 'trait']);
        });

        $columns = array_merge(['id', 'school_id'], $this->traits);
        foreach (DB::table('term_reports')->select($columns)->cursor() as $report) {
            $rows = [];
            foreach ($this->traits as $trait) {
                $score = $report->{$trait};
                if ($score === null || (int) $score < 1 || (int) $score > 5) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'school_id' => $report->school_id,
                    'term_report_id' => $report->id,
                    'trait' => $trait,
                    'score' => (int) $score,
                ];
            }
            if ($rows !== []) {
                DB::table('term_report_ratings')->insert($rows);
            }
        }

        Schema::table('term_reports', function (Blueprint $table) {
            $table->dropColumn($this->traits);
        });
    }

    public function down(): void
    {
        Schema::table('term_reports', function (Blueprint $table) {
            foreach ($this->traits as $trait) {
                $table->unsignedTinyInteger($trait)->nullable();
            }
        });

        foreach (DB::table('term_report_ratings')->cursor() as $rating) {
            if (! in_array($rating->trait, $this->traits, true)) {
                continue;
            }
            DB::table('term_reports')->where('id', $rating->term_report_id)->update([
                $rating->trait => (int) $rating->score,
            ]);
        }

        Schema::dropIfExists('term_report_ratings');
    }
};
