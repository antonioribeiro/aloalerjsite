<?php

use App\Data\Models\Area;
use App\Data\Models\Origin;
use App\Data\Models\ProgressType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMissingData01 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Origin::create(['name' => 'Outros']);
        Origin::create(['name' => 'Carta Resposta']);
        ProgressType::create(['name' => 'Resposta']);
        Area::create(['name' => 'Defesa Civil']);
        Area::create(['name' => 'Prefeitura']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
}
