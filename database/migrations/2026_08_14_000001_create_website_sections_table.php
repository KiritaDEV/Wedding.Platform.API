<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_enabled')->default(true);
            $table->json('content');
            $table->timestamps();

            $table->index(['website_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_sections');
    }
};
