<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'thumbnail', 'total_articles', 'description'])]
class Category extends Model
{
    use HasUuids;

    public function articles(): HasMany
    {
        return $this->hasMany(Articles::class);
    }

    public static function createCategory(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            $category = self::query()->create([
                ...$attributes,
                'slug' => Str::slug($attributes['name']),
            ]);

            return $category->refresh();
        });
    }
}
