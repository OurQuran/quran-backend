<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
        ALTER TABLE qiraat_readings
        ALTER COLUMN imam TYPE jsonb USING jsonb_build_object(\'en\', imam, \'ar\', \'\', \'ku\', \'\'),
        ALTER COLUMN riwaya TYPE jsonb USING jsonb_build_object(\'en\', riwaya, \'ar\', \'\', \'ku\', \'\'),
        ALTER COLUMN name TYPE jsonb USING jsonb_build_object(\'en\', name, \'ar\', \'\', \'ku\', \'\')
    ');
    }

    public function down(): void
    {
        DB::statement('
        ALTER TABLE qiraat_readings
        ALTER COLUMN imam TYPE text USING imam->>\'en\',
        ALTER COLUMN riwaya TYPE text USING riwaya->>\'en\',
        ALTER COLUMN name TYPE text USING name->>\'en\'
    ');
    }
};
