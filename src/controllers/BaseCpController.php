<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Base CP Controller for Asset Guardian
 *
 * Enforces CP requests and permissions across all plugin CP views.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
abstract class BaseCpController extends Controller
{
    /**
     * @var string|null Specific permission required for this controller, or null if only general access is required
     */
    protected ?string $requiredPermission = null;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Require CP request
        $this->requireCpRequest();

        // Require base plugin access permission
        $this->requirePermission('accessPlugin-asset-guardian');

        // Require granular action permission if specified
        if ($this->requiredPermission !== null) {
            $this->requirePermission($this->requiredPermission);
        }

        return true;
    }

    /**
     * Sets a success notice flash in the CP
     *
     * @param string $message
     */
    protected function setSuccessNotification(string $message): void
    {
        $this->setSuccessFlash($message);
    }

    /**
     * Sets a failure error flash in the CP
     *
     * @param string $message
     */
    protected function setFailNotification(string $message): void
    {
        $this->setFailFlash($message);
    }

    /**
     * Renders a CP template with standardized variables
     *
     * @param string $template
     * @param array $variables
     * @return Response
     */
    protected function renderCpTemplate(string $template, array $variables = []): Response
    {
        $variables['pluginHandle'] = 'asset-guardian';
        $variables['pluginName'] = 'Asset Guardian';

        return $this->renderTemplate($template, $variables);
    }
}
