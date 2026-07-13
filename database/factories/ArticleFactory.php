<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(4);

        return [
            'author_id' => null,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'thumbnail' => null,
            'excerpt' => $this->faker->sentence(),
            'content' => $this->faker->paragraphs(3, true),
            'is_published' => true,
            'published_at' => now()->subDay(),
        ];
    }
}
