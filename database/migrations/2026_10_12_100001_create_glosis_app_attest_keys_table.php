<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Glosis gratisnivå: en rad per App Attest-nyckel (en installation av appen på
 * en enhet). Ingen koppling till person, konto eller barn. Gallras efter 13
 * månader utan användning (glosis:prune-attest-keys). Bara additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glosis_app_attest_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            // base64(SHA256(publika nyckeln)), som appen skickar den. base64 är skiftlägeskänsligt, men
            // MySQL:s standardsortering (utf8mb4_unicode_ci) är det inte: då kunde två olika nycklar
            // krocka i unique-indexet och en uppslagning träffa fel rad. ascii_bin jämför byte för byte.
            // SQLite (testerna) känner inte till ascii_bin men jämför redan binärt (BINARY) som standard.
            $keyId = $table->string('key_id', 44);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $keyId->charset('ascii')->collation('ascii_bin');
            }
            $table->text('public_key');            // PEM ur credCert
            $table->unsignedInteger('sign_count')->default(0);
            $table->string('environment', 16);     // production | development
            $table->string('bundle_version', 64)->nullable();
            $table->unsignedInteger('validation_category')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'key_id']);
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glosis_app_attest_keys');
    }
};
