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
        // Drop the surrogate id and add the composite primary key in a single
        // ALTER so the table is never momentarily without a primary key. Managed
        // MySQL with sql_require_primary_key=ON (e.g. Scalingo) rejects the
        // intermediate PK-less state that Laravel's two-statement form produces.
        DB::statement('ALTER TABLE joint_permissions DROP COLUMN id, ADD PRIMARY KEY (role_id, entity_type, entity_id, action)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('joint_permissions', function (Blueprint $table) {
            $table->dropPrimary(['role_id', 'entity_type', 'entity_id', 'action']);
        });

        Schema::table('joint_permissions', function (Blueprint $table) {
            $table->increments('id')->unsigned();
        });
    }
};
