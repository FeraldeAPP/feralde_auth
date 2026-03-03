<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Google/Facebook tokens can be 1500+ chars — VARCHAR(255) is too small
            $table->text('provider_token')->nullable()->change();
            $table->text('provider_refresh_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('provider_token')->nullable()->change();
            $table->string('provider_refresh_token')->nullable()->change();
        });
    }
};
