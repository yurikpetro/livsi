<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Битые ссылки и переадресации, которые владелец настраивает в админке.
 *
 * missing_pages — адреса, на которых посетитель или робот получил 404:
 * старые ссылки из выдачи, из соцсетей, с других сайтов.
 * seo_redirects — куда такие адреса вести (301).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('path', 300)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('bot_hits')->default(0);
            $table->string('last_referer', 255)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->boolean('ignored')->default(false);
            $table->timestamps();
        });

        Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 300)->unique();
            $table->string('to_url', 500);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
        Schema::dropIfExists('missing_pages');
    }
};
