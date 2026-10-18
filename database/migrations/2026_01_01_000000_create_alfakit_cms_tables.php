<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `tenant_id` holds the key of the panel's tenant model on tenancy panels (null otherwise).
 * Change it to `uuid()` / `ulid()` before migrating when your tenant keys are not integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('alfakit_cms_pages')->nullOnDelete();
            $table->foreignId('translation_of_id')->nullable()->constrained('alfakit_cms_pages')->nullOnDelete();
            $table->string('locale', 16)->nullable();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('path', 512)->default('')->index();
            $table->boolean('is_home')->default(false);
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('publish_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->json('content')->nullable();
            $table->json('seo')->nullable();
            // Saved changes of a published page that aren't live yet (and autosaves).
            $table->json('draft')->nullable();
            $table->timestamp('draft_saved_at')->nullable();
            // The review workflow: status (pending, changes_requested), note, who and when.
            $table->json('review')->nullable();
            $table->string('layout')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('alfakit_cms_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('alfakit_cms_pages')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('data');
            $table->nullableMorphs('author');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'number']);
        });

        Schema::create('alfakit_cms_redirects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('from_path', 512)->index();
            $table->string('to_url', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alfakit_cms_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->json('content')->nullable();
            $table->timestamps();
        });

        Schema::create('alfakit_cms_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
        });

        Schema::create('alfakit_cms_menus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('handle', 64)->index();
            $table->string('locale', 16)->nullable();
            $table->json('items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_cms_menus');
        Schema::dropIfExists('alfakit_cms_templates');
        Schema::dropIfExists('alfakit_cms_sections');
        Schema::dropIfExists('alfakit_cms_redirects');
        Schema::dropIfExists('alfakit_cms_page_revisions');
        Schema::dropIfExists('alfakit_cms_pages');
    }
};
