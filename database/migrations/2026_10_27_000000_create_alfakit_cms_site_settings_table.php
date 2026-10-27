<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site design (colours, fonts, logo) and the custom code of each site: one row per
 * tenant, `tenant_id` null without tenancy. Change `tenant_id` to `uuid()` / `ulid()`
 * before migrating when your tenant keys are not integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_cms_site_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->json('design')->nullable();
            $table->json('code')->nullable();
            $table->json('code_history')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_cms_site_settings');
    }
};
