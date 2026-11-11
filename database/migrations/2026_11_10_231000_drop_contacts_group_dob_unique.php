<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date of birth is not a unique identity. Two contacts in the same group can
 * share a birthday; uniqueness stays on phone and email only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('contacts', 'contacts_group_dob_unique')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropUnique('contacts_group_dob_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('contacts', 'contacts_group_dob_unique')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->unique(['contact_group_id', 'date_of_birth'], 'contacts_group_dob_unique');
            });
        }
    }
};
