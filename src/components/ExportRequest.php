<?php declare(strict_types=1);

namespace hiqdev\yii2\export\components;

use hiqdev\hiart\ActiveDataProvider;

final class ExportRequest
{
    public function __construct(
        public readonly string $format,
        public readonly array $representationColumns,
        public readonly string $gridClassName,
        public readonly ActiveDataProvider $dataProvider,
    ) {
    }
}
