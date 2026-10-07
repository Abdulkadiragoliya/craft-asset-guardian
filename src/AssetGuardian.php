<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use craft\helpers\UrlHelper;
use yii\base\Event;
use abdulkadiragoliya\assetguardian\models\Settings;
use abdulkadiragoliya\assetguardian\services\CleanupService;
use abdulkadiragoliya\assetguardian\services\DuplicateService;
use abdulkadiragoliya\assetguardian\services\HealthService;
use abdulkadiragoliya\assetguardian\services\ReportService;
use abdulkadiragoliya\assetguardian\services\ScanService;
use abdulkadiragoliya\assetguardian\services\UsageService;

/**
 * Asset Guardian plugin for Craft CMS 5.x
 *
 * Asset Health & Safe Cleanup for Craft CMS
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian
 * @since 1.0.0
 *
 * @property-read Settings $settings
 * @property-read ScanService $scan
 * @property-read HealthService $health
 * @property-read UsageService $usage
 * @property-read DuplicateService $duplicate
 * @property-read CleanupService $cleanup
 * @property-read ReportService $report
 * @method Settings getSettings()
 * @method ScanService getScan()
 * @method HealthService getHealth()
 * @method UsageService getUsage()
 * @method DuplicateService getDuplicate()
 * @method CleanupService getCleanup()
 * @method ReportService getReport()
 */
class AssetGuardian extends Plugin
{
    /**
     * @var AssetGuardian|null
     */
    public static ?AssetGuardian $plugin = null;

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'scan' => ScanService::class,
                'health' => HealthService::class,
                'usage' => UsageService::class,
                'duplicate' => DuplicateService::class,
                'cleanup' => CleanupService::class,
                'report' => ReportService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerTemplateRoots();
        $this->_registerCpRoutes();
        $this->_registerPermissions();
    }

    /**
     * @inheritdoc
     */
    protected function cpNavIconPath(): ?string
    {
        $path = $this->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg';
        if (is_file($path)) {
            return $path;
        }

        return parent::cpNavIconPath();
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();
        if ($navItem === null) {
            return null;
        }

        $user = Craft::$app->getUser();
        if (!$user->checkPermission('accessPlugin-asset-guardian')) {
            return null;
        }

        if (empty($navItem['icon'])) {
            $iconPath = $this->cpNavIconPath();
            if ($iconPath) {
                $navItem['icon'] = $iconPath;
            }
        }

        $navItem['url'] = 'asset-guardian';
        $subnav = [];

        if ($user->checkPermission('assetGuardian-viewDashboard')) {
            $subnav['dashboard'] = [
                'label' => Craft::t('asset-guardian', 'Dashboard'),
                'url' => 'asset-guardian/dashboard',
            ];
        }

        if ($user->checkPermission('assetGuardian-viewUnusedAssets')) {
            $subnav['unused'] = [
                'label' => Craft::t('asset-guardian', 'Unused Assets'),
                'url' => 'asset-guardian/unused',
            ];
        }

        if ($user->checkPermission('assetGuardian-viewDuplicates')) {
            $subnav['duplicates'] = [
                'label' => Craft::t('asset-guardian', 'Duplicates'),
                'url' => 'asset-guardian/duplicates',
            ];
        }

        if ($user->checkPermission('assetGuardian-viewLargeFiles')) {
            $subnav['large-files'] = [
                'label' => Craft::t('asset-guardian', 'Large Files'),
                'url' => 'asset-guardian/large-files',
            ];
        }

        if ($user->checkPermission('assetGuardian-viewMissingAltText')) {
            $subnav['missing-alt'] = [
                'label' => Craft::t('asset-guardian', 'Missing Alt Text'),
                'url' => 'asset-guardian/missing-alt',
            ];
        }

        if ($user->checkPermission('assetGuardian-viewAssetExplorer')) {
            $subnav['explorer'] = [
                'label' => Craft::t('asset-guardian', 'Asset Explorer'),
                'url' => 'asset-guardian/explorer',
            ];
        }

        if ($user->checkPermission('assetGuardian-manageCleanup')) {
            $subnav['cleanup'] = [
                'label' => Craft::t('asset-guardian', 'Cleanup Center'),
                'url' => 'asset-guardian/cleanup',
            ];
        }

        if ($user->checkPermission('assetGuardian-exportReports')) {
            $subnav['reports'] = [
                'label' => Craft::t('asset-guardian', 'Reports'),
                'url' => 'asset-guardian/reports',
            ];
        }

        if ($user->checkPermission('assetGuardian-viewHistory')) {
            $subnav['history'] = [
                'label' => Craft::t('asset-guardian', 'Scan History'),
                'url' => 'asset-guardian/history',
            ];
        }

        if ($user->checkPermission('assetGuardian-manageSettings')) {
            $subnav['settings'] = [
                'label' => Craft::t('asset-guardian', 'Settings'),
                'url' => 'asset-guardian/settings',
            ];
        }

        $navItem['subnav'] = $subnav;

        return $navItem;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
       // return Craft::$app->getResponse()->redirect('asset-guardian/settings');
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('asset-guardian/settings'));
		
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'asset-guardian/settings/_plugin-settings',
            [
                'settings' => $this->getSettings(),
            ]
        );
    }

    /**
     * Registers template roots for CP views
     */
    private function _registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['asset-guardian'] = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
            }
        );
    }

    /**
     * Registers CP URL rules for Asset Guardian navigation
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['asset-guardian'] = 'asset-guardian/dashboard/index';
                $event->rules['asset-guardian/dashboard'] = 'asset-guardian/dashboard/index';
                $event->rules['asset-guardian/dashboard/scan'] = 'asset-guardian/dashboard/scan';
                $event->rules['asset-guardian/dashboard/status'] = 'asset-guardian/dashboard/status';
                $event->rules['asset-guardian/unused'] = 'asset-guardian/unused/index';
                $event->rules['asset-guardian/unused/detail'] = 'asset-guardian/unused/detail';
                $event->rules['asset-guardian/duplicates'] = 'asset-guardian/duplicates/index';
                $event->rules['asset-guardian/duplicates/repoint-review'] = 'asset-guardian/duplicates/repoint-review';
                $event->rules['asset-guardian/duplicates/repoint'] = 'asset-guardian/duplicates/repoint';
                $event->rules['asset-guardian/large-files'] = 'asset-guardian/large-files/index';
                $event->rules['asset-guardian/missing-alt'] = 'asset-guardian/missing-alt/index';
                $event->rules['asset-guardian/missing-alt/save-alt'] = 'asset-guardian/missing-alt/save-alt';
                $event->rules['asset-guardian/explorer'] = 'asset-guardian/explorer/index';
                $event->rules['asset-guardian/cleanup'] = 'asset-guardian/cleanup/index';
                $event->rules['asset-guardian/cleanup/simulate'] = 'asset-guardian/cleanup/simulate';
                $event->rules['asset-guardian/cleanup/execute'] = 'asset-guardian/cleanup/execute';
                $event->rules['asset-guardian/cleanup/restore'] = 'asset-guardian/cleanup/restore';
                $event->rules['asset-guardian/reports'] = 'asset-guardian/reports/index';
                $event->rules['asset-guardian/reports/download'] = 'asset-guardian/reports/download';
                $event->rules['asset-guardian/history'] = 'asset-guardian/history/index';
                $event->rules['asset-guardian/settings'] = 'asset-guardian/settings/index';
            }
        );
    }

    /**
     * Registers granular permissions for Asset Guardian
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('asset-guardian', 'Asset Guardian'),
                    'permissions' => [
                        'assetGuardian-viewDashboard' => [
                            'label' => Craft::t('asset-guardian', 'View Dashboard'),
                        ],
                        'assetGuardian-runScans' => [
                            'label' => Craft::t('asset-guardian', 'Run Scans'),
                        ],
                        'assetGuardian-viewUnusedAssets' => [
                            'label' => Craft::t('asset-guardian', 'View Unused Assets'),
                        ],
                        'assetGuardian-viewDuplicates' => [
                            'label' => Craft::t('asset-guardian', 'View Duplicate Assets'),
                        ],
                        'assetGuardian-viewLargeFiles' => [
                            'label' => Craft::t('asset-guardian', 'View Large Files'),
                        ],
                        'assetGuardian-viewMissingAltText' => [
                            'label' => Craft::t('asset-guardian', 'View Missing Alt Text'),
                        ],
                        'assetGuardian-manageAltText' => [
                            'label' => Craft::t('asset-guardian', 'Edit & Update Alt Text Metadata'),
                        ],
                        'assetGuardian-viewAssetExplorer' => [
                            'label' => Craft::t('asset-guardian', 'Use Asset Explorer'),
                        ],
                        'assetGuardian-manageCleanup' => [
                            'label' => Craft::t('asset-guardian', 'Execute Cleanup Operations'),
                        ],
                        'assetGuardian-restoreCleanup' => [
                            'label' => Craft::t('asset-guardian', 'Restore Cleanup Operations'),
                        ],
                        'assetGuardian-exportReports' => [
                            'label' => Craft::t('asset-guardian', 'Download Reports (CSV)'),
                        ],
                        'assetGuardian-viewHistory' => [
                            'label' => Craft::t('asset-guardian', 'View Scan History & Audit Logs'),
                        ],
                        'assetGuardian-manageSettings' => [
                            'label' => Craft::t('asset-guardian', 'Manage Plugin Settings'),
                        ],
                    ],
                ];
            }
        );
    }
}
