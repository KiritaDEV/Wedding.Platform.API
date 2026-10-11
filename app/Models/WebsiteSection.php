<?php

namespace App\Models;

use App\Website\WebsiteSectionRegistry;
use Database\Factories\WebsiteSectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSection extends Model
{
    /** @use HasFactory<WebsiteSectionFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'content' => '[]',
    ];

    protected $fillable = [
        'type',
        'singleton_key',
        'editor_name',
        'sort_order',
        'is_enabled',
        'content',
        'appearance',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
            'content' => 'array',
            'appearance' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WebsiteSection $section): void {
            $definition = app(WebsiteSectionRegistry::class)->get($section->type);
            $section->singleton_key = $definition?->lifecycle->isSingleton() === true ? $definition->key : null;
            if ($definition?->lifecycle->isUserOwned() !== true) {
                $section->editor_name = null;
            }
        });
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
