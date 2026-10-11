<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('variant_key', 32);
            $table->string('mime_type', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->timestamps();
            $table->unique(['media_asset_id', 'variant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
