<?php

declare(strict_types=1);

namespace SuperExport\Universal;

enum EntityType: string
{
    case Post = 'posts';
    case Page = 'pages';
    case Product = 'products';
    case Category = 'categories';
    case Tag = 'tags';
    case Meta = 'meta';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public function isContent(): bool
    {
        return in_array($this, [self::Post, self::Page, self::Product], true);
    }

    public function isTaxonomy(): bool
    {
        return in_array($this, [self::Category, self::Tag], true);
    }
}
