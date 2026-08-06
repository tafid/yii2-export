<?php declare(strict_types=1);

namespace hiqdev\yii2\export\tests\fixtures;

use hiqdev\yii2\export\exporters\CSVExporter;

/**
 * CSVExporter's row/header/footer logic is `protected`, by design - it is
 * meant to be called through the public export()/exportToFile() flow. This
 * test-only subclass exposes it directly so unit tests can exercise column
 * transformation and sanitization without needing a real HiAPI-backed
 * ActiveDataProvider (see AbstractExporterRowsTest for why: those methods
 * only touch $this->grid, not the data provider).
 */
final class ExposedCsvExporter extends CSVExporter
{
    public function compileRowPublic($model, $key, $index): array
    {
        return $this->compileRow($model, $key, $index);
    }

    public function generateHeaderPublic()
    {
        return $this->generateHeader();
    }

    public function generateFooterPublic()
    {
        return $this->generateFooter();
    }

    public function sanitizeRowPublic(?string $value): ?string
    {
        return $this->sanitizeRow($value);
    }
}
