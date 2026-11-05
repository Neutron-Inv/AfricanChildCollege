<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->ulid('principal_signature_file_id')->nullable()->after('logo_file_id');
            $table->foreign('principal_signature_file_id')
                ->references('id')
                ->on('stored_files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropForeign(['principal_signature_file_id']);
            $table->dropColumn('principal_signature_file_id');
        });
    }
};
