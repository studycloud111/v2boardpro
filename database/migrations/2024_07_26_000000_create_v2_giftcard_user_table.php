<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2GiftcardUserTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('v2_giftcard_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('giftcard_id');
            $table->integer('user_id');
            $table->timestamps();

            $table->index('giftcard_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_giftcard_user');
    }
} 