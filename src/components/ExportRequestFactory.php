<?php declare(strict_types=1);

namespace hiqdev\yii2\export\components;

use hipanel\actions\IndexAction;
use hipanel\base\Controller;
use hipanel\widgets\SynchronousCountEnabler;
use hiqdev\hiart\ActiveDataProvider;
use hiqdev\yii2\export\actions\StartExportAction;
use RuntimeException;
use Yii;

/**
 * Resolves everything that {@see Exporter::runJob()} needs from a live
 * {@see StartExportAction}/web controller, and packs it into a plain
 * {@see ExportRequest}. This is the only place in the export pipeline that
 * is coupled to the web/controller layer, so the rest of the pipeline can be
 * exercised in tests without a real HTTP request.
 */
final class ExportRequestFactory
{
    public function fromStartExportAction(StartExportAction $action, array $representationColumns): ExportRequest
    {
        return new ExportRequest(
            format: $this->getExportFormat($action),
            representationColumns: $representationColumns,
            gridClassName: $this->guessGridClassName($action->controller),
            dataProvider: $this->wrapDataProvider($action),
        );
    }

    private function getExportFormat(IndexAction $action): string
    {
        return $action->controller->request->get('format');
    }

    private function guessGridClassName(Controller $controller): string
    {
        $controllerName = str_replace(' ', '', ucwords(str_replace('-', ' ', $controller->id)));
        $ns = implode(
            '\\',
            array_diff(explode('\\', get_class($controller)), [
                $controllerName . 'Controller',
                'controllers',
            ])
        );
        $gridClassName = sprintf('\%s\grid\%sGridView', $ns, $controllerName);
        if (class_exists($gridClassName)) {
            return $gridClassName;
        }

        throw new RuntimeException("ExportAction cannot find a $gridClassName");
    }

    private function wrapDataProvider(IndexAction $action): ActiveDataProvider
    {
        $dataProvider = $this->getDataProvider($action);
        $enabler = new SynchronousCountEnabler($dataProvider);

        return $enabler->getDataProvider();
    }

    private function getDataProvider(IndexAction $action): ActiveDataProvider
    {
        $indexConfig = $this->getIndexActionConfig($action);

        if ($indexConfig !== null) {
            $indexAction = $this->createIndexAction($indexConfig, $action);
            $indexAction->beforePerform();

            return $indexAction->getDataProvider();
        }

        return $action->getDataProvider();
    }

    private function getIndexActionConfig(IndexAction $action): ?array
    {
        $actions = $action->controller->actions();

        return $actions['index'] ?? null;
    }

    private function createIndexAction(array $config, IndexAction $action): IndexAction
    {
        $config['forceStorageFiltersApply'] = true;

        return Yii::createObject($config, ['index', $action->controller]);
    }
}
