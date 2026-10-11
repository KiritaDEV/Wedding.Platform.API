<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_private_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('encrypted_token');
            $table->enum('current_slot', ['current'])->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            // NULL permits history; the non-NULL slot permits only one current link.
            $table->unique(['invitation_id', 'current_slot']);
            $table->index(['invitation_id', 'created_at']);
        });

        Schema::create('invitation_browser_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
            $table->char('secret_hash', 64)->unique();
            $table->enum('current_slot', ['current'])->nullable();
            $table->string('browser_family')->nullable();
            $table->string('platform')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // No credential is created here. This only enforces the future one-current invariant.
            $table->unique(['invitation_id', 'current_slot']);
            $table->index(['invitation_id', 'created_at']);
        });

        // MySQL ENUM has an internal empty-string error value in non-strict modes;
        // explicit checks keep the invariant independent of connection SQL mode.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invitation_private_links ADD CONSTRAINT invitation_private_links_current_slot_check CHECK (current_slot IS NULL OR current_slot = 'current')");
            DB::statement("ALTER TABLE invitation_browser_credentials ADD CONSTRAINT invitation_browser_credentials_current_slot_check CHECK (current_slot IS NULL OR current_slot = 'current')");
        }

        DB::table('invitations')->select('id')->orderBy('id')->chunk(100, function ($invitations): void {
            foreach ($invitations as $invitation) {
                do {
                    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $hash = hash('sha256', $token);
                } while (DB::table('invitation_private_links')->where('token_hash', $hash)->exists());

                $now = now();
                DB::table('invitation_private_links')->insert([
                    'id' => (string) Str::ulid(),
                    'invitation_id' => $invitation->id,
                    'token_hash' => $hash,
                    'encrypted_token' => Crypt::encryptString($token),
                    'current_slot' => 'current',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_browser_credentials');
        Schema::dropIfExists('invitation_private_links');
    }
};
