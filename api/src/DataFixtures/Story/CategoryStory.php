<?php

declare(strict_types=1);

namespace App\DataFixtures\Story;

use App\Entity\Category;
use App\Factory\CategoryFactory;

final class CategoryStory
{
    public static function load(): void
    {
        CategoryFactory::createMany(5);
    }
}
