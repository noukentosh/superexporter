<?php

declare(strict_types=1);

namespace SuperExport\Universal\Entity;

use SuperExport\Universal\EntityType;

final class Category extends TaxonomyEntity
{
    public static function entityType(): EntityType
    {
        return EntityType::Category;
    }
}
