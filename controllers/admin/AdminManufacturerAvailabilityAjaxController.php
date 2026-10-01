<?php
/**
 * AJAX endpoint of the Availability by manufacturer module.
 * Protected by the standard back office token and tab permissions.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'manufactureravailability/classes/ManufacturerAvailabilityCompat.php';
require_once _PS_MODULE_DIR_ . 'manufactureravailability/classes/ManufacturerAvailabilityService.php';

class AdminManufacturerAvailabilityAjaxController extends ModuleAdminController
{
    public function postProcess()
    {
        if (!Tools::getValue('ajax')) {
            $this->respond(false, $this->module->t('Invalid request.'));
        }
        if (!Validate::isLoadedObject($this->context->employee) || !$this->context->employee->isLoggedBack()) {
            $this->respond(false, $this->module->t('Access denied.'));
        }

        $action = (string) Tools::getValue('action');

        // actions that are not tied to a manufacturer
        switch ($action) {
            case 'add_preset':
                $this->actionAddPreset();
                break;
            case 'delete_preset':
                $this->actionDeletePreset();
                break;
            case 'update_preset':
                $this->actionUpdatePreset();
                break;
            case 'save_settings':
                $this->actionSaveSettings();
                break;
        }

        $idManufacturer = (int) Tools::getValue('id_manufacturer');
        if (!ManufacturerAvailabilityService::manufacturerExists($idManufacturer)) {
            $this->respond(false, $this->module->t('Invalid request.'));
        }

        switch ($action) {
            case 'save_manufacturer':
                $this->actionSaveManufacturer($idManufacturer);
                break;
            case 'scan_exceptions':
                $this->actionScanExceptions($idManufacturer);
                break;
            case 'get_exceptions':
                $this->actionGetExceptions($idManufacturer);
                break;
            case 'save_exceptions':
                $this->actionSaveExceptions($idManufacturer);
                break;
            case 'clear_exceptions':
                $this->actionClearExceptions($idManufacturer);
                break;
        }

        $this->respond(false, $this->module->t('Invalid request.'));
    }

    protected function actionSaveManufacturer($idManufacturer)
    {
        $active = Tools::getValue('active') ? 1 : 0;
        $mode = (int) Tools::getValue('out_of_stock');
        if ($mode < 0 || $mode > 3) {
            $mode = ManufacturerAvailabilityService::MODE_KEEP;
        }
        $labels = Tools::getValue('label');
        if (!is_array($labels)) {
            $labels = array();
        }

        $result = ManufacturerAvailabilityService::saveManufacturer($idManufacturer, $active, $mode, $labels);
        $params = array(
            '%count%' => (int) $result['updated'],
            '%exceptions%' => (int) $result['protected'],
        );
        if ($result['reset']) {
            $message = $this->module->t('Module switched off for this manufacturer. Products restored to shop defaults: %count%.', $params);
        } elseif ($result['protected'] > 0) {
            $message = $this->module->t('Settings saved. Updated products: %count%. Products with their own settings automatically added to exceptions: %exceptions%.', $params);
        } else {
            $message = $this->module->t('Settings saved. Updated products: %count%.', $params);
        }

        $this->respond(true, $message, array('exceptions' => (int) $result['exceptions']));
    }

    protected function actionScanExceptions($idManufacturer)
    {
        $posted = Tools::getValue('out_of_stock');
        $mode = ($posted === false || $posted === '') ? null : (int) $posted;
        $result = ManufacturerAvailabilityService::scanExceptions($idManufacturer, $mode);

        $this->respond(
            true,
            $this->module->t('Scan finished. Products added to exceptions: %count%.', array('%count%' => (int) $result['added'])),
            array('exceptions' => (int) $result['exceptions'])
        );
    }

    protected function actionGetExceptions($idManufacturer)
    {
        $name = trim((string) Tools::getValue('name'));
        $reference = trim((string) Tools::getValue('ref'));
        $offset = max(0, (int) Tools::getValue('offset'));
        $limit = min(200, max(10, (int) Tools::getValue('limit', 100)));

        $data = ManufacturerAvailabilityService::getProducts($idManufacturer, $name, $reference, $offset, $limit);
        $extra = array('products' => $data['products'], 'total' => $data['total']);
        if (Tools::getValue('with_ids')) {
            $extra['ids'] = ManufacturerAvailabilityService::getExceptionIds($idManufacturer);
        }

        $this->respond(true, '', $extra);
    }

    protected function actionSaveExceptions($idManufacturer)
    {
        $decoded = json_decode((string) Tools::getValue('ids'), true);
        if (!is_array($decoded)) {
            $decoded = array();
        }
        $result = ManufacturerAvailabilityService::saveExceptions($idManufacturer, $decoded);

        $this->respond(
            true,
            $this->module->t('Exceptions saved. Updated products: %count%.', array('%count%' => (int) $result['updated'])),
            array('exceptions' => (int) $result['count'])
        );
    }

    protected function actionClearExceptions($idManufacturer)
    {
        $result = ManufacturerAvailabilityService::clearExceptions($idManufacturer);

        $this->respond(
            true,
            $this->module->t('All exceptions deleted. Updated products: %count%.', array('%count%' => (int) $result['updated'])),
            array('exceptions' => 0)
        );
    }

    protected function actionAddPreset()
    {
        $texts = Tools::getValue('texts');
        $presets = is_array($texts) ? ManufacturerAvailabilityService::addPreset($texts) : false;
        if ($presets === false) {
            $this->respond(false, $this->module->t('Enter the label text in at least one language.'));
        }

        $this->respond(true, $this->module->t('Label added to the list.'), array('presets' => $presets));
    }

    protected function actionDeletePreset()
    {
        $key = (string) Tools::getValue('key');
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $key)) {
            $this->respond(false, $this->module->t('Invalid request.'));
        }

        $presets = ManufacturerAvailabilityService::deletePreset($key);

        $this->respond(true, $this->module->t('Label removed from the list.'), array('presets' => $presets));
    }

    protected function actionUpdatePreset()
    {
        $key = (string) Tools::getValue('key');
        $texts = Tools::getValue('texts');
        if (!is_array($texts)) {
            $texts = array();
        }

        $presets = ManufacturerAvailabilityService::updatePreset($key, $texts);
        if ($presets === false) {
            $this->respond(false, $this->module->t('Could not update the label. Enter text in at least one language and make sure the label is unique.'));
        }

        $this->respond(true, $this->module->t('Label updated.'), array('presets' => $presets));
    }

    protected function actionSaveSettings()
    {
        $input = array();
        foreach (ManufacturerAvailabilityService::getUiSettingKeys() as $key) {
            $input[$key] = Tools::getValue($key) ? 1 : 0;
        }
        $settings = ManufacturerAvailabilityService::saveUiSettings($input);

        $this->respond(true, $this->module->t('Display settings saved.'), array('settings' => $settings));
    }

    protected function respond($success, $message = '', $extra = array())
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(array('success' => (bool) $success, 'message' => (string) $message), $extra));
        exit;
    }
}
