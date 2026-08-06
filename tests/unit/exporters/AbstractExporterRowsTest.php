<?php declare(strict_types=1);

namespace hiqdev\yii2\export\tests\unit\exporters;

use hiqdev\hiart\ActiveDataProvider;
use hiqdev\yii2\export\tests\fixtures\ExposedCsvExporter;
use PHPUnit\Framework\TestCase;
use Yii;

/**
 * Covers the parts of AbstractExporter that only depend on $this->grid
 * (built by initExportOptions() from a plain ActiveDataProvider + column
 * config), not on a live HiAPI connection: header/footer generation, row
 * compilation, and CSV-injection sanitization. generateBody() itself (the
 * HiAPI-backed part) is exercised indirectly by ExportStreamingSmokeTest via
 * the same Generator-based writer path, without needing a real API call.
 */
final class AbstractExporterRowsTest extends TestCase
{
    private function makeExporter(array $columns): ExposedCsvExporter
    {
        $exporter = new ExposedCsvExporter();

        $dataProvider = new ActiveDataProvider();
        $exporter->setDataProvider($dataProvider);
        $exporter->setGridClassName(\yii\grid\GridView::class);
        $exporter->setRepresentationColumns($columns);
        $exporter->initExportOptions();

        return $exporter;
    }

    public function testGenerateHeaderUsesColumnLabels(): void
    {
        $exporter = $this->makeExporter([
            ['attribute' => 'id', 'label' => 'ID'],
            ['attribute' => 'name', 'label' => 'Name'],
        ]);

        $this->assertSame(['ID', 'Name'], $exporter->generateHeaderPublic());
    }

    public function testCompileRowReadsAttributesFromModel(): void
    {
        $exporter = $this->makeExporter([
            ['attribute' => 'id', 'label' => 'ID'],
            ['attribute' => 'name', 'label' => 'Name'],
        ]);

        $model = ['id' => 42, 'name' => 'DTG1148'];

        $this->assertSame(['42', 'DTG1148'], $exporter->compileRowPublic($model, 42, 0));
    }

    public function testGenerateFooterIsEmptyByDefault(): void
    {
        $exporter = $this->makeExporter([
            ['attribute' => 'id', 'label' => 'ID'],
        ]);

        $this->assertSame([null], $exporter->generateFooterPublic());
    }

    public function testSanitizeRowStripsTagsAndCsvInjectionPrefix(): void
    {
        $exporter = $this->makeExporter([['attribute' => 'id', 'label' => 'ID']]);

        $this->assertSame('alert', $exporter->sanitizeRowPublic('<b>alert</b>'));
        $this->assertSame('SUM(A1:A9)', $exporter->sanitizeRowPublic('=SUM(A1:A9)'));
        $this->assertNull($exporter->sanitizeRowPublic(null));
        $this->assertNull($exporter->sanitizeRowPublic(''));
    }
}
