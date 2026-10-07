<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use yii\web\Response;

/**
 * Settings Controller
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class SettingsController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-manageSettings';

    /**
     * Display Settings page
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $plugin = AssetGuardian::getInstance();
        $settings = $plugin->getSettings();
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        $volumeOptions = [];
        foreach ($volumes as $volume) {
            $volumeOptions[] = [
                'label' => $volume->name,
                'value' => $volume->handle,
            ];
        }

        return $this->renderCpTemplate('asset-guardian/settings/index', [
            'selectedSubnavItem' => 'settings',
            'title' => Craft::t('asset-guardian', 'Settings'),
            'settings' => $settings,
            'volumeOptions' => $volumeOptions,
        ]);
    }

    /**
     * Save plugin settings
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = AssetGuardian::getInstance();
        $settings = Craft::$app->getRequest()->getBodyParam('settings');
        if ($settings === null) {
            $settings = Craft::$app->getRequest()->getBodyParams();
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('app', 'Couldn’t save plugin settings.'));

            // Send back to settings index
            return $this->actionIndex();
        }

        $this->setSuccessFlash(Craft::t('asset-guardian', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
