<?php declare(strict_types=1);

namespace hiqdev\yii2\export\tests\smoke;

use hiqdev\yii2\export\exporters\CSVExporter;
use hiqdev\yii2\export\exporters\XLSXExporter;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AbstractExporter::exportToFile()'s streaming writer path
 * end-to-end (real OpenSpout writers, real files on disk), through the same
 * `$sections` Generator entry point hipanel-core's DataExportAction already
 * uses in production - so this needs no HiAPI connection or ActiveDataProvider
 * fixture, while still covering the exact code changed in the "stream rows
 * instead of buffering the whole export" commit: chunked addRows() calls
 * across a WRITE_CHUNK_SIZE (500) boundary.
 *
 * Exporter::runJob()'s HiAPI-backed generateBody() path is not covered here;
 * that would need a hiart Connection/ActiveDataProvider fixture, left as
 * follow-up (see docs/investigations/export-performance.md open questions).
 */
final class ExportStreamingSmokeTest extends TestCase
{
    private const ROW_COUNT = 1200; // > 2x WRITE_CHUNK_SIZE, forces multiple addRows() flushes

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function tempPath(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/yii2-export-smoke-' . bin2hex(random_bytes(8)) . $suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function fixtureRows(): \Generator
    {
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            yield ['id' => (string) $i, 'name' => 'row-' . $i];
        }
    }

    public function testCsvStreamsAllRowsAcrossMultipleWriteChunks(): void
    {
        $exporter = new CSVExporter();
        $path = $this->tempPath('.csv');

        $exporter->exportToFile($path, [
            'data' => fn() => $this->fixtureRows(),
        ]);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        $handle = fopen($path, 'rb');
        $rowCount = 0;
        $lastRow = null;
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rowCount++;
            $lastRow = $row;
        }
        fclose($handle);

        $this->assertSame(self::ROW_COUNT, $rowCount, 'every fixture row must reach the file, including rows written after a chunk flush');
        $this->assertSame(['1200', 'row-1200'], $lastRow, 'the last row must survive the final partial-chunk flush');
    }

    public function testXlsxStreamsAllRowsAcrossMultipleWriteChunks(): void
    {
        $exporter = new XLSXExporter();
        $path = $this->tempPath('.xlsx');

        $exporter->exportToFile($path, [
            'data' => fn() => $this->fixtureRows(),
        ]);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        $reader = new XlsxReader();
        $reader->open($path);
        $rowCount = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowCount++;
            }
        }
        $reader->close();

        $this->assertSame(self::ROW_COUNT, $rowCount);
    }
}
