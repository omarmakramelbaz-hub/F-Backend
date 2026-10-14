<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateMetaAiControls extends Migration
{
    public function up()
    {
        Schema::create('meta_ai_controls', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('policy');
            $table->text('activations');
            $table->text('handoff')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $now = gmdate('Y-m-d H:i:s');
        DB::table('meta_ai_controls')->insert(['id' => 1,
            'policy' => json_encode(['enabled' => false, 'channels' => ['whatsapp', 'messenger', 'instagram'],
                'schedule' => ['enabled' => false, 'days' => [0, 1, 2, 3, 4, 5, 6],
                    'start' => '09:00', 'end' => '23:00', 'all_day' => true]], JSON_THROW_ON_ERROR),
            'activations' => '{}', 'handoff' => null, 'revision' => 0, 'actor_id' => null, 'updated_by' => null,
            'created_at' => $now, 'updated_at' => $now]);
    }

    public function down() { Schema::dropIfExists('meta_ai_controls'); }
}
