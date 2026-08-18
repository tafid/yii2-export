<?php declare(strict_types=1);

namespace hiqdev\yii2\export\tests\unit\actions;

use hipanel\grid\RepresentationCollectionFinder;
use hiqdev\higrid\representations\RepresentationCollection;
use hiqdev\higrid\representations\RepresentationCollectionInterface;
use hiqdev\yii2\export\actions\StartExportAction;
use hiqdev\yii2\export\models\ExportJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use yii\web\Controller;

/**
 * Simulates the real production failure already observed in runtime logs:
 * ExportRequestFactory::guessGridClassName() (or anything else resolved
 * before Exporter::runJob() is reached) throwing for a controller whose
 * grid class doesn't exist. Overriding ensureRepresentationCollection() is
 * enough to force that failure without needing a real grid/HiAPI-backed
 * controller.
 */
final class ThrowingStartExportAction extends StartExportAction
{
    protected function ensureRepresentationCollection(): RepresentationCollection|RepresentationCollectionInterface
    {
        throw new RuntimeException('grid class not found (simulated)');
    }
}

final class StartExportActionTest extends TestCase
{
    public function testExportJobReachesTerminalStateWhenResolutionFailsBeforeRunJob(): void
    {
        $controller = new Controller('test', null);
        $finder = new RepresentationCollectionFinder('test', 'test', '%s\\hipanel\\modules\\%s\\grid\\%sRepresentations');
        $action = new ThrowingStartExportAction('start-export', $controller, $finder);
        $id = '1234567890';

        (new ReflectionMethod($action, 'performExport'))->invoke($action, $id);

        $job = ExportJob::findOrCreate($id);
        $this->assertFalse($job->isNew(), 'a job record must exist even though runJob() was never reached');
        $this->assertTrue($job->needToTerminate(), 'the job must be in a terminal state, not stuck as "running" forever');
        $this->assertStringContainsString('grid class not found', (string) $job->getErrorMessage());
    }
}
