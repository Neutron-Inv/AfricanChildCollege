<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->ulid('second_signature_file_id')->nullable()->after('principal_signature_file_id');
            $table->string('second_signature_label')->default('')->after('second_signature_file_id');
            $table->foreign('second_signature_file_id')
                ->references('id')
                ->on('stored_files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropForeign(['second_signature_file_id']);
            $table->dropColumn(['second_signature_file_id', 'second_signature_label']);
        });
    }
};
