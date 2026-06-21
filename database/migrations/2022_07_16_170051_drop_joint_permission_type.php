<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('joint_permissions')
            ->where('action', '!=', 'view')
            ->delete();

        // Swap the primary key in a single ALTER so the table is never momentarily
        // without a primary key (rejected by managed MySQL with
        // sql_require_primary_key=ON, e.g. Scalingo).
        DB::statement('ALTER TABLE joint_permissions DROP PRIMARY KEY, DROP COLUMN action, ADD PRIMARY KEY (role_id, entity_type, entity_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('joint_permissions', function (Blueprint $table) {
            $table->string('action');
            $table->dropPrimary(['role_id', 'entity_type', 'entity_id']);
            $table->primary(['role_id', 'entity_type', 'entity_id', 'action']);
        });
    }
};
