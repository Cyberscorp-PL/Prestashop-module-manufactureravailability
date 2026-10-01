<?php
/**
 * Availability by manufacturer
 *
 * Manages "When out of stock" and "Label when out of stock (and back order allowed)"
 * for all products of a manufacturer from one screen.
 *
 * PHP 8.0 - 8.5, PrestaShop 1.7.0 - 9.2
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/ManufacturerAvailabilityCompat.php';
require_once dirname(__FILE__) . '/classes/ManufacturerAvailabilityService.php';

class Manufactureravailability extends Module
{
    const TAB_CLASS = 'AdminManufacturerAvailabilityAjax';

    public function __construct()
    {
        $this->name = 'manufactureravailability';
        $this->tab = 'administration';
        $this->version = '1.0.10';
        $this->author = 'Cyberscorp';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array(
            'min' => ManufacturerAvailabilityCompat::PS_MIN,
            'max' => ManufacturerAvailabilityCompat::PS_MAX,
        );

        parent::__construct();

        $this->displayName = $this->t('Availability by manufacturer');
        $this->description = $this->t('Set the out-of-stock behaviour and the availability label for all products of a manufacturer in one place.');
        $this->confirmUninstall = $this->t('Are you sure you want to uninstall this module? Settings already written to products will stay unchanged.');
    }

    /**
     * Version aware translation wrapper (Symfony translator or legacy l()).
     */
    public function t($string, $params = array())
    {
        try {
            return ManufacturerAvailabilityCompat::translate($this, $string, $params);
        } catch (Exception $e) {
            return strtr($string, $params);
        } catch (Throwable $e) {
            return strtr($string, $params);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        if (!$this->installDb()) {
            $this->_errors[] = 'Database: ' . Db::getInstance()->getMsgError();

            return false;
        }
        if (!$this->installTab()) {
            $this->_errors[] = 'Admin tab could not be created.';

            return false;
        }
        if (!$this->registerModuleHooks()) {
            $this->_errors[] = 'Hooks could not be registered.';

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->uninstallDb()
            && parent::uninstall();
    }

    protected function registerModuleHooks()
    {
        $hooks = array(
            // legacy product page (1.7.x, 8.x) - fired after the whole product form was saved
            'actionProductSave',
            // fired by every PrestaShop version on ObjectModel add / update
            'actionObjectProductAddAfter',
            'actionObjectProductUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
        );
        if (ManufacturerAvailabilityCompat::hasNewProductPage()) {
            // new product page (Symfony forms)
            $hooks[] = 'actionAfterCreateProductFormHandler';
            $hooks[] = 'actionAfterUpdateProductFormHandler';
        }

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    protected function installDb()
    {
        $engine = _MYSQL_ENGINE_;
        $p = _DB_PREFIX_;
        $queries = array(
            'CREATE TABLE IF NOT EXISTS `' . $p . 'mfa_manufacturer` (
                `id_manufacturer` INT(10) UNSIGNED NOT NULL,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `out_of_stock` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_upd` DATETIME NULL,
                PRIMARY KEY (`id_manufacturer`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . $p . 'mfa_manufacturer_lang` (
                `id_manufacturer` INT(10) UNSIGNED NOT NULL,
                `id_lang` INT(10) UNSIGNED NOT NULL,
                `label` VARCHAR(255) NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id_manufacturer`, `id_lang`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . $p . 'mfa_exception` (
                `id_manufacturer` INT(10) UNSIGNED NOT NULL,
                `id_product` INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (`id_manufacturer`, `id_product`),
                KEY `id_product` (`id_product`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
        );

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    protected function uninstallDb()
    {
        $p = _DB_PREFIX_;
        Configuration::deleteByName(ManufacturerAvailabilityService::CONFIG_PRESETS);
        Configuration::deleteByName(ManufacturerAvailabilityService::CONFIG_SETTINGS);

        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . $p . 'mfa_exception`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . $p . 'mfa_manufacturer_lang`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . $p . 'mfa_manufacturer`');
    }

    /**
     * Hidden back office controller used for AJAX calls.
     */
    protected function installTab()
    {
        if ((int) Tab::getIdFromClassName(self::TAB_CLASS) > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = self::TAB_CLASS;
        $tab->module = $this->name;
        $tab->id_parent = -1; // not displayed in the menu
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Manufacturer availability AJAX';
        }

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName(self::TAB_CLASS);
        if ($idTab > 0) {
            $tab = new Tab($idTab);

            return (bool) $tab->delete();
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                              */
    /* ------------------------------------------------------------------ */

    public function hookActionProductSave($params)
    {
        $id = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($id <= 0 && isset($params['product']) && is_object($params['product'])) {
            $id = (int) $params['product']->id;
        }
        ManufacturerAvailabilityService::applyToProduct($id);
    }

    public function hookActionObjectProductAddAfter($params)
    {
        ManufacturerAvailabilityService::applyToProduct($this->getObjectId($params));
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        ManufacturerAvailabilityService::applyToProduct($this->getObjectId($params));
    }

    public function hookActionAfterCreateProductFormHandler($params)
    {
        ManufacturerAvailabilityService::applyToProduct(isset($params['id']) ? (int) $params['id'] : 0);
    }

    public function hookActionAfterUpdateProductFormHandler($params)
    {
        ManufacturerAvailabilityService::applyToProduct(isset($params['id']) ? (int) $params['id'] : 0);
    }

    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        $id = $this->getObjectId($params);
        if ($id > 0) {
            ManufacturerAvailabilityService::deleteManufacturerData($id);
        }
    }

    protected function getObjectId($params)
    {
        if (isset($params['object']) && is_object($params['object']) && isset($params['object']->id)) {
            return (int) $params['object']->id;
        }

        return 0;
    }

    /* ------------------------------------------------------------------ */
    /* Back office page                                                   */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $languages = Language::getLanguages(false);
        $currentLang = (int) $this->context->language->id;
        usort($languages, function ($a, $b) use ($currentLang) {
            if ((int) $a['id_lang'] === $currentLang) {
                return -1;
            }
            if ((int) $b['id_lang'] === $currentLang) {
                return 1;
            }

            return (int) $a['id_lang'] - (int) $b['id_lang'];
        });

        $ajaxUrl = ManufacturerAvailabilityCompat::getAjaxUrl();

        $rows = ManufacturerAvailabilityService::getOverview();
        foreach ($rows as &$row) {
            $row['products_label'] = $row['products'] > 0
                ? $this->t('%count% products', array('%count%' => $row['products']))
                : $this->t('No products assigned');
        }
        unset($row);

        $allowByDefault = (int) Configuration::get('PS_ORDER_OUT_OF_STOCK') === 1;
        $defaultBehaviour = $allowByDefault ? $this->t('Allow orders') : $this->t('Deny orders');

        $jsLangs = array();
        foreach ($languages as $lang) {
            $jsLangs[] = array('id' => (int) $lang['id_lang'], 'iso' => strtolower($lang['iso_code']));
        }

        $settings = ManufacturerAvailabilityService::getUiSettings();

        $config = array(
            'ajaxUrl' => $ajaxUrl,
            'moduleDisplayName' => $this->displayName,
            'moduleVersion' => $this->version,
            'langs' => $jsLangs,
            'presets' => ManufacturerAvailabilityService::getPresets(),
            'settings' => $settings,
            'adminIso' => strtolower($this->context->language->iso_code),
            'locale' => $this->context->language->language_code,
            'tr' => array(
                'error' => $this->t('An error occurred while saving. Please try again.'),
                'confirmClear' => $this->t('Are you sure you want to delete all exceptions of this manufacturer?'),
                'loading' => $this->t('Loading...'),
                'noProducts' => $this->t('No products found.'),
                'selected' => $this->t('Selected exceptions: %count%'),
                'modalTitle' => $this->t('Exceptions - %name%'),
                'tipExceptions' => $this->t('Exceptions: %exceptions% of %total% products are not changed by the module'),
                'tipNone' => $this->t('No exceptions'),
                'scanTitle' => $this->t('Scan products and add those that already have their own settings to the exceptions'),
                'preset' => $this->t('Choose a template or type your own text'),
                'exceptionsLabel' => $this->t('Exceptions'),
                'scanLabel' => $this->t('Scan'),
                'nothingToSave' => $this->t('Nothing to save.'),
                'savedCount' => $this->t('Saved manufacturers: %count%'),
                'saveFailed' => $this->t('Some manufacturers could not be saved: %count%'),
                'addLabel' => $this->t('Add label'),
                'removeLabel' => $this->t('Remove label'),
                'emptyList' => $this->t('The list of labels is empty.'),
                'btnAdd' => $this->t('Add'),
                'btnRemove' => $this->t('Remove'),
                'close' => $this->t('Close'),
                'enterText' => $this->t('Enter the label text in at least one language.'),
                'cfgLabelEmpty' => $this->t('The list of labels is empty.'),
                'cfgLabelSave' => $this->t('Save label'),
                'cfgLabelDelete' => $this->t('Delete label'),
                'cfgLabelConfirmDelete' => $this->t('Are you sure you want to delete this label?'),
            ),
        );

        $tr = array(
            'tab_list' => $this->t('Manufacturers list'),
            'tab_config' => $this->t('Configuration'),
            'tab_info' => $this->t('Information'),
            'col_active' => $this->t('Active'),
            'col_id' => $this->t('ID'),
            'col_logo' => $this->t('Logo'),
            'col_name' => $this->t('Manufacturer'),
            'col_oos' => $this->t('When out of stock'),
            'col_label' => $this->t('Availability label'),
            'col_exc' => $this->t('Exceptions'),
            'save' => $this->t('Save'),
            'keep' => $this->t('Do not change current setting'),
            'no_logo' => $this->t('Logo file not found'),
            'deny' => $this->t('Deny orders'),
            'allow' => $this->t('Allow orders'),
            'default' => $this->t('Use default behaviour (%behaviour%)', array('%behaviour%' => $defaultBehaviour)),
            'legend' => $this->t('Manufacturers without any product are highlighted in red.'),
            'preset' => $this->t('Choose a template or type your own text'),
            'plus_title' => $this->t('Add the label to the dropdown list'),
            'switch_title' => $this->t('Enable or disable the module for this manufacturer'),
            'lang' => $this->t('Language'),
            'no_manufacturers' => $this->t('No manufacturers found.'),
            'f_name' => $this->t('Filter by product name'),
            'f_ref' => $this->t('Filter by product code'),
            'th_id' => $this->t('ID'),
            'th_code' => $this->t('Code'),
            'th_name' => $this->t('Product name'),
            'th_exc' => $this->t('Exception'),
            'btn_save_exc' => $this->t('Add / change exceptions'),
            'btn_clear' => $this->t('Delete all'),
            'btn_close' => $this->t('Close'),
            'sel_all' => $this->t('Select all visible'),
            'sel_none' => $this->t('Unselect all visible'),
            'load_more' => $this->t('Load more'),
            'bar_filter' => $this->t('Filter'),
            'f_active' => $this->t('Only active'),
            'f_inactive' => $this->t('Only inactive'),
            'f_exc' => $this->t('With exceptions'),
            'f_all' => $this->t('All'),
            'bar_labels' => $this->t('Labels'),
            'add_label' => $this->t('Add label'),
            'remove_label' => $this->t('Remove label'),
            'save_all' => $this->t('Save all'),
            'cfg_title' => $this->t('Displayed fields'),
            'cfg_id' => $this->t('Show manufacturer ID'),
            'cfg_logo' => $this->t('Show manufacturer logo'),
            'cfg_name' => $this->t('Show manufacturer name'),
            'cfg_exc_text' => $this->t('Show text labels next to the exceptions and scan icons'),
            'cfg_label_text' => $this->t('Show text labels next to the label management icons'),
            'cfg_labels_title' => $this->t('Availability labels'),
            'cfg_labels_intro' => $this->t('Manage all labels available in the dropdown. You can edit, add or remove them.'),
            'cfg_label_add' => $this->t('Add label'),
            'cfg_label_save' => $this->t('Save label'),
            'cfg_label_delete' => $this->t('Delete label'),
            'cfg_label_language' => $this->t('Language'),
            'cfg_label_empty' => $this->t('The list of labels is empty.'),
            'cfg_label_confirm_delete' => $this->t('Are you sure you want to delete this label?'),
            'info_title' => $this->t('What does this module do?'),
            'info_version_title' => $this->t('Version-dependent code'),
            'info_intro' => $this->t('The module lets you manage, from one place, the product setting "When out of stock" and the label "Label when out of stock (and back order allowed)" for all products of a manufacturer.'),
            'info_bullets' => array(
                $this->t('Each manufacturer has its own switch, out-of-stock behaviour and availability label (in every language).'),
                $this->t('After saving, the values are written to all products of that manufacturer.'),
                $this->t('Switching a manufacturer off restores the shop default behaviour and clears the label in its products.'),
                $this->t('Products marked as exceptions are never changed by the module.'),
                $this->t('When a manufacturer is switched on, products that already have their own out-of-stock setting or label are automatically added to the exceptions.'),
                $this->t('With "Do not change current setting" the module only fills in the availability label and leaves the out-of-stock behaviour of the products untouched.'),
                $this->t('New or edited products of an active manufacturer receive the settings automatically.'),
                $this->t('The scan button adds to the exceptions every product that already has its own settings, so they are never overwritten.'),
                $this->t('Manufacturers without any product are highlighted in red.'),
            ),
            'vs_text' => $this->t('The module detects the PHP and PrestaShop version at runtime and automatically selects the matching translation system, hooks and product page handling. The PHP version you can use is limited by your PrestaShop version.'),
            'compat' => $this->t('Compatibility'),
            'php_supported' => $this->t('Supported PHP versions'),
            'ps_supported' => $this->t('Supported PrestaShop versions'),
            'php_detected' => $this->t('Detected PHP version'),
            'ps_detected' => $this->t('Detected PrestaShop version'),
            'ok' => $this->t('Supported'),
            'nok' => $this->t('Not supported'),
            'module_version' => $this->t('Module version'),
            'logo_dir' => $this->t('Logo directory'),
            'logo_files' => $this->t('Logo files found'),
            'gd' => $this->t('GD library'),
            'available' => $this->t('Available'),
            'not_available' => $this->t('Not available'),
        );

        $this->context->smarty->assign(array(
            'mfa_tr' => $tr,
            'mfa_rows' => $rows,
            'mfa_langs' => $languages,
            'mfa_settings' => $settings,
            'mfa_config' => json_encode($config),
            'mfa_css' => $this->_path . 'views/css/admin.css?v=' . $this->version,
            'mfa_js' => $this->_path . 'views/js/admin.js?v=' . $this->version,
            'mfa_version' => $this->version,
            'mfa_php_range' => ManufacturerAvailabilityCompat::PHP_MIN . ' - 8.5',
            'mfa_ps_range' => ManufacturerAvailabilityCompat::PS_MIN . ' - ' . ManufacturerAvailabilityCompat::PS_MAX,
            'mfa_php_current' => PHP_VERSION,
            'mfa_ps_current' => _PS_VERSION_,
            'mfa_logo_dir' => _PS_MANU_IMG_DIR_,
            'mfa_logo_files' => ManufacturerAvailabilityCompat::countManufacturerLogoFiles(),
            'mfa_gd' => ManufacturerAvailabilityCompat::isGdAvailable(),
            'mfa_php_ok' => ManufacturerAvailabilityCompat::isPhpSupported(),
            'mfa_ps_ok' => ManufacturerAvailabilityCompat::isPsSupported(),
        ));

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }
}
