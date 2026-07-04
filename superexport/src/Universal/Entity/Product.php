<?php

declare(strict_types=1);

namespace SuperExport\Universal\Entity;

use SuperExport\Universal\EntityType;

final class Product extends ContentEntity
{
    public static function entityType(): EntityType
    {
        return EntityType::Product;
    }
}
