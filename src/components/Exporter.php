<?php declare(strict_types=1);

namespace hiqdev\yii2\export\components;

use Exception;
use hiqdev\yii2\export\exporters\ExporterFactoryInterface;
use hiqdev\yii2\export\exporters\ExporterInterface;
use hiqdev\yii2\export\exporters\ExportType;
use hiqdev\yii2\export\models\ExportJob;
use Yii;
use yii\base\Component;

class Exporter extends Component
{
    private ?ExportJob $job = null;

    public function __construct(public ExporterFactoryInterface $exporterFactory, $config = [])
    {
        parent::__construct($config);
    }

    public function runJob(string $id, ExportRequest $request): void
    {
        $this->job = ExportJob::findOrCreate($id);

        try {
            $exportHandler = $this->prepareExporter($request);
        } catch (Exception $e) {
            Yii::error('Export error: ' . $e->getMessage());
            $this->handleExportError($e);
            return;
        }

        $exportHandler->setExportJob($this->job);

        if (!$this->job->isNew()) {
            // Guard clause for invalid job state
            $this->handleInvalidJobState();

            return;
        }

        $this->job->begin();

        try {
            $exportHandler->export($this->job);
            $this->job->end();
        } catch (Exception $e) {
            $this->handleExportError($e);
            throw $e;
        }
    }

    private function handleInvalidJobState(): void
    {
        Yii::error('Export: The export job must be STATUS_NEW. ' . $this->job->errorMessage);
    }

    private function handleExportError(Exception $e): void
    {
        $this->job->cancel($e->getMessage());
        Yii::error('Export error: ' . $e->getMessage());
    }

    private function prepareExporter(ExportRequest $request): ExporterInterface
    {
        $exporter = $this->initializeExporter($request);
        $exporter->initExportOptions();

        return $exporter;
    }

    public function __destruct()
    {
        if (isset($this->job) && !$this->job->isSuccess()) {
            $this->job->cancel('There was probably an internal error during Report generating. Contact the development team.');
        }
    }

    private function initializeExporter(ExportRequest $request): ExporterInterface
    {
        $exporter = $this->createExporter($request->format, $request->representationColumns);
        $exporter->setDataProvider($request->dataProvider);
        $exporter->setGridClassName($request->gridClassName);

        return $exporter;
    }

    private function createExporter(string $type, array $representationColumns): ExporterInterface
    {
        $exporter = $this->getExporter($type);
        $exporter->setRepresentationColumns($representationColumns);

        return $exporter;
    }

    private function getExporter(string $format): ExporterInterface
    {
        $type = ExportType::tryFrom($format);

        return $this->exporterFactory->build($type);
    }
}
