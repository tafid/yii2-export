<?php

declare(strict_types=1);


namespace hiqdev\yii2\export\actions;

use hipanel\actions\IndexAction;
use hipanel\actions\RunProcessAction;
use hiqdev\yii2\export\components\ExportRequestFactory;
use Yii;
use yii\web\BadRequestHttpException;

class StartExportAction extends IndexAction
{
    public function run(): void
    {
        if ($this->controller->request->isAjax) {
            $action = new RunProcessAction('start-export', $this->controller);
            $action->onRunProcess = function () {
                $id = $this->controller->request->post('export_id');
                if (!ctype_digit($id) || strlen($id) !== 10) {
                    throw new BadRequestHttpException('Invalid export ID format');
                }
                $representation = $this->ensureRepresentationCollection()->getByName($this->getUiModel()->representation);
                $request = (new ExportRequestFactory())->fromStartExportAction($this, $representation->getColumns());
                Yii::$app->exporter->runJob($id, $request);
            };
            $action->run();
        }
        Yii::$app->end();
    }
}
