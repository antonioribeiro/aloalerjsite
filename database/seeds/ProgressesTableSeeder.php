<?php

use Illuminate\Database\Seeder;
use App\Data\Models\Progress as ProgressModel;

class ProgressesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        factory(ProgressModel::class, 50)->create();
    }
}
