<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 40)->index();
            // = slug medan en provisioneringsrun håller namnet, annars null.
            // Unikt index med null tillåter flera släppta runs (även i MySQL).
            $table->string('slug_lock', 40)->nullable()->unique();
            $table->string('name', 100);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->text('idea')->nullable();
            $table->string('github_repo')->nullable();
            $table->string('coolify_app_uuid', 64)->nullable()->index();
            $table->unsignedBigInteger('siteops_site_id')->nullable();
            $table->string('demo_url')->nullable();
            // Sätts när SiteOps-övervakningen av Demo-miljön slogs på (första lyckade deploy).
            $table->timestamp('monitoring_enabled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_runs');
    }
};
