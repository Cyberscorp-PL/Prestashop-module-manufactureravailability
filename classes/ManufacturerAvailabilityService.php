<?php
/**
 * Business logic: settings storage, exceptions and writing values to products.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ManufacturerAvailabilityService
{
    const MODE_DENY = 0;
    const MODE_ALLOW = 1;
    const MODE_DEFAULT = 2;
    /** module-only value: do not touch the out-of-stock behaviour of the products */
    const MODE_KEEP = 3;

    const LABEL_MAX_LENGTH = 255;
    const CHUNK_SIZE = 500;
    const MAX_PRESETS = 100;

    const CONFIG_PRESETS = 'MFA_PRESETS';
    const CONFIG_SETTINGS = 'MFA_SETTINGS';

    /** @var array|null id_manufacturer => out_of_stock mode, for active manufacturers only */
    protected static $activeMap = null;

    /* ------------------------------------------------------------------ */
    /* Reading                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array list of manufacturers with counters and settings
     */
    public static function getOverview()
    {
        $p = _DB_PREFIX_;
        $sql = 'SELECT m.`id_manufacturer`, m.`name`,
                    IFNULL(pc.`cnt`, 0) AS products,
                    IFNULL(xc.`cnt`, 0) AS exceptions,
                    IFNULL(s.`active`, 0) AS active,
                    IFNULL(s.`out_of_stock`, 3) AS out_of_stock
                FROM `' . $p . 'manufacturer` m
                LEFT JOIN (
                    SELECT `id_manufacturer`, COUNT(*) AS cnt FROM `' . $p . 'product`
                    WHERE `id_manufacturer` > 0 GROUP BY `id_manufacturer`
                ) pc ON pc.`id_manufacturer` = m.`id_manufacturer`
                LEFT JOIN (
                    SELECT e.`id_manufacturer`, COUNT(*) AS cnt FROM `' . $p . 'mfa_exception` e
                    INNER JOIN `' . $p . 'product` pr
                        ON pr.`id_product` = e.`id_product` AND pr.`id_manufacturer` = e.`id_manufacturer`
                    GROUP BY e.`id_manufacturer`
                ) xc ON xc.`id_manufacturer` = m.`id_manufacturer`
                LEFT JOIN `' . $p . 'mfa_manufacturer` s ON s.`id_manufacturer` = m.`id_manufacturer`
                ORDER BY m.`name` ASC';

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return array();
        }

        $languages = Language::getLanguages(false);
        $allLabels = self::getAllLabels();

        foreach ($rows as &$row) {
            $id = (int) $row['id_manufacturer'];
            $row['id_manufacturer'] = $id;
            $row['products'] = (int) $row['products'];
            $row['exceptions'] = (int) $row['exceptions'];
            $row['active'] = (int) $row['active'];
            $row['out_of_stock'] = (int) $row['out_of_stock'];
            $row['logo'] = ManufacturerAvailabilityCompat::getManufacturerLogoDataUri($id);
            $labels = array();
            foreach ($languages as $lang) {
                $idLang = (int) $lang['id_lang'];
                $labels[$idLang] = isset($allLabels[$id][$idLang]) ? $allLabels[$id][$idLang] : '';
            }
            $row['labels'] = $labels;
        }
        unset($row);

        return $rows;
    }

    protected static function getAllLabels()
    {
        $out = array();
        $rows = Db::getInstance()->executeS(
            'SELECT `id_manufacturer`, `id_lang`, `label` FROM `' . _DB_PREFIX_ . 'mfa_manufacturer_lang`'
        );
        foreach ((array) $rows as $r) {
            $out[(int) $r['id_manufacturer']][(int) $r['id_lang']] = (string) $r['label'];
        }

        return $out;
    }

    public static function manufacturerExists($idManufacturer)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE `id_manufacturer` = ' . (int) $idManufacturer
        );
    }

    public static function getSettings($idManufacturer)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `active`, `out_of_stock` FROM `' . _DB_PREFIX_ . 'mfa_manufacturer`
             WHERE `id_manufacturer` = ' . (int) $idManufacturer
        );
        if (!$row) {
            return array('active' => 0, 'out_of_stock' => self::MODE_KEEP);
        }

        return array('active' => (int) $row['active'], 'out_of_stock' => (int) $row['out_of_stock']);
    }

    public static function getActiveMap()
    {
        if (self::$activeMap === null) {
            self::$activeMap = array();
            $rows = Db::getInstance()->executeS(
                'SELECT `id_manufacturer`, `out_of_stock` FROM `' . _DB_PREFIX_ . 'mfa_manufacturer` WHERE `active` = 1'
            );
            foreach ((array) $rows as $r) {
                self::$activeMap[(int) $r['id_manufacturer']] = (int) $r['out_of_stock'];
            }
        }

        return self::$activeMap;
    }

    /**
     * Labels to write into products: every language gets its own text,
     * an empty language falls back to the shop default language text.
     *
     * @return array id_lang => text
     */
    public static function resolveLabels($idManufacturer)
    {
        $stored = array();
        $rows = Db::getInstance()->executeS(
            'SELECT `id_lang`, `label` FROM `' . _DB_PREFIX_ . 'mfa_manufacturer_lang`
             WHERE `id_manufacturer` = ' . (int) $idManufacturer
        );
        foreach ((array) $rows as $r) {
            $stored[(int) $r['id_lang']] = trim((string) $r['label']);
        }

        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $fallback = isset($stored[$defaultLang]) ? $stored[$defaultLang] : '';

        $out = array();
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $text = isset($stored[$idLang]) ? $stored[$idLang] : '';
            $out[$idLang] = ($text !== '') ? $text : $fallback;
        }

        return $out;
    }

    public static function getExceptionIds($idManufacturer)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT e.`id_product` FROM `' . _DB_PREFIX_ . 'mfa_exception` e
             INNER JOIN `' . _DB_PREFIX_ . 'product` p
                ON p.`id_product` = e.`id_product` AND p.`id_manufacturer` = e.`id_manufacturer`
             WHERE e.`id_manufacturer` = ' . (int) $idManufacturer
        );
        $ids = array();
        foreach ((array) $rows as $r) {
            $ids[] = (int) $r['id_product'];
        }

        return $ids;
    }

    /**
     * Products of a manufacturer with optional filtering (for the exceptions popup).
     *
     * @return array array('products' => array, 'total' => int)
     */
    public static function getProducts($idManufacturer, $name, $reference, $offset, $limit)
    {
        $p = _DB_PREFIX_;
        $idLang = (int) Context::getContext()->language->id;

        $from = ' FROM `' . $p . 'product` p
                  LEFT JOIN `' . $p . 'product_lang` pl
                    ON (pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = p.`id_shop_default`)
                  WHERE p.`id_manufacturer` = ' . (int) $idManufacturer;
        if ($name !== '') {
            $from .= ' AND pl.`name` LIKE \'%' . pSQL($name, false) . '%\'';
        }
        if ($reference !== '') {
            $from .= ' AND p.`reference` LIKE \'%' . pSQL($reference, false) . '%\'';
        }

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*)' . $from);
        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, p.`active`, IFNULL(pl.`name`, \'\') AS name' . $from .
            ' ORDER BY pl.`name` ASC, p.`id_product` ASC LIMIT ' . (int) $offset . ', ' . (int) $limit
        );

        $products = array();
        foreach ((array) $rows as $r) {
            $products[] = array(
                'id' => (int) $r['id_product'],
                'reference' => (string) $r['reference'],
                'name' => (string) $r['name'],
                'active' => (int) $r['active'],
            );
        }

        return array('products' => $products, 'total' => $total);
    }

    /* ------------------------------------------------------------------ */
    /* Writing settings                                                   */
    /* ------------------------------------------------------------------ */

    public static function sanitizeLabel($text)
    {
        $text = trim(strip_tags((string) $text));
        // characters rejected by the product "available_later" validation
        $text = preg_replace('/[<>;=#{}]/u', '', $text);
        if ($text === null) {
            $text = '';
        }
        $text = Tools::substr($text, 0, self::LABEL_MAX_LENGTH);

        return trim($text);
    }

    /**
     * Stores settings and immediately applies them to the products.
     *
     * @param int   $idManufacturer
     * @param int   $active
     * @param int   $mode   0 deny, 1 allow, 2 default
     * @param array $labels id_lang => text
     *
     * @return array array('updated' => int, 'reset' => bool)
     */
    public static function saveManufacturer($idManufacturer, $active, $mode, array $labels)
    {
        $idManufacturer = (int) $idManufacturer;
        $db = Db::getInstance();
        $previous = self::getSettings($idManufacturer);
        $wasActive = !empty($previous['active']);

        $db->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'mfa_manufacturer` (`id_manufacturer`, `active`, `out_of_stock`, `date_upd`)
             VALUES (' . $idManufacturer . ', ' . (int) (bool) $active . ', ' . (int) $mode . ', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        );

        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'mfa_manufacturer_lang` WHERE `id_manufacturer` = ' . $idManufacturer);
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $text = isset($labels[$idLang]) ? self::sanitizeLabel($labels[$idLang]) : '';
            $db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'mfa_manufacturer_lang` (`id_manufacturer`, `id_lang`, `label`)
                 VALUES (' . $idManufacturer . ', ' . $idLang . ', \'' . pSQL($text) . '\')'
            );
        }
        self::$activeMap = null;

        $updated = 0;
        $reset = false;
        $protected = 0;
        if ($active) {
            if (!$wasActive) {
                // switching on: never overwrite products that already have their own settings
                $protected = self::protectCustomProducts($idManufacturer, (int) $mode, false);
            }
            $updated = self::applyManufacturer($idManufacturer);
        } elseif ($wasActive) {
            // when the module never touched the stock behaviour (KEEP), leave it as it is
            $restoreStock = ((int) $previous['out_of_stock'] !== self::MODE_KEEP);
            $updated = self::resetManufacturer($idManufacturer, $restoreStock);
            $reset = true;
        }
        ManufacturerAvailabilityCompat::cleanCaches();

        return array(
            'updated' => $updated,
            'reset' => $reset,
            'protected' => $protected,
            'exceptions' => count(self::getExceptionIds($idManufacturer)),
        );
    }

    /**
     * Adds to the exceptions the products that already have their own settings.
     *
     * $compareWithTarget = false (module is off, nothing was written by it yet):
     *   a product is protected when its label is not empty or, unless the mode is KEEP,
     *   its "when out of stock" is 0 or 1 (anything other than "use default").
     * $compareWithTarget = true (module is on and already wrote its values):
     *   a product is protected when its label or stock behaviour differs from what the
     *   module would write, so products written by the module itself are not affected.
     *
     * @return int number of products newly added to the exceptions
     */
    public static function protectCustomProducts($idManufacturer, $mode = null, $compareWithTarget = false)
    {
        $idManufacturer = (int) $idManufacturer;
        $p = _DB_PREFIX_;
        $db = Db::getInstance();

        if ($mode === null) {
            $settings = self::getSettings($idManufacturer);
            $mode = $settings['out_of_stock'];
        }
        $mode = (int) $mode;

        $conditions = array();
        if ($compareWithTarget) {
            $parts = array();
            foreach (self::resolveLabels($idManufacturer) as $idLang => $text) {
                $parts[] = '(pl.`id_lang` = ' . (int) $idLang . ' AND IFNULL(pl.`available_later`, \'\') <> \'' . pSQL($text) . '\')';
            }
            if (!empty($parts)) {
                $conditions[] = 'EXISTS (SELECT 1 FROM `' . $p . 'product_lang` pl WHERE pl.`id_product` = p.`id_product` AND (' . implode(' OR ', $parts) . '))';
            }
            if ($mode !== self::MODE_KEEP) {
                $conditions[] = 'EXISTS (SELECT 1 FROM `' . $p . 'stock_available` sa WHERE sa.`id_product` = p.`id_product` AND sa.`out_of_stock` <> ' . $mode . ')';
            }
        } else {
            $conditions[] = 'EXISTS (SELECT 1 FROM `' . $p . 'product_lang` pl WHERE pl.`id_product` = p.`id_product` AND pl.`available_later` <> \'\')';
            if ($mode !== self::MODE_KEEP) {
                $conditions[] = 'EXISTS (SELECT 1 FROM `' . $p . 'stock_available` sa WHERE sa.`id_product` = p.`id_product` AND sa.`out_of_stock` IN (0, 1))';
            }
        }
        if (empty($conditions)) {
            return 0;
        }

        $rows = $db->executeS(
            'SELECT p.`id_product` FROM `' . $p . 'product` p
             WHERE p.`id_manufacturer` = ' . $idManufacturer . '
             AND NOT EXISTS (
                SELECT 1 FROM `' . $p . 'mfa_exception` e
                WHERE e.`id_manufacturer` = ' . $idManufacturer . ' AND e.`id_product` = p.`id_product`
             )
             AND (' . implode(' OR ', $conditions) . ')'
        );

        $ids = array();
        foreach ((array) $rows as $r) {
            $ids[] = (int) $r['id_product'];
        }

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $values = array();
            foreach ($chunk as $idProduct) {
                $values[] = '(' . $idManufacturer . ', ' . (int) $idProduct . ')';
            }
            $db->execute(
                'INSERT IGNORE INTO `' . $p . 'mfa_exception` (`id_manufacturer`, `id_product`) VALUES ' . implode(',', $values)
            );
        }

        return count($ids);
    }

    /**
     * "Scan" button: protects products that already have their own settings.
     *
     * @param int      $idManufacturer
     * @param int|null $postedMode mode currently selected in the form (used while the module is off)
     *
     * @return array array('added' => int, 'exceptions' => int)
     */
    public static function scanExceptions($idManufacturer, $postedMode = null)
    {
        $idManufacturer = (int) $idManufacturer;
        $settings = self::getSettings($idManufacturer);
        $isActive = !empty($settings['active']);

        if ($isActive) {
            $added = self::protectCustomProducts($idManufacturer, $settings['out_of_stock'], true);
        } else {
            $mode = ($postedMode !== null) ? (int) $postedMode : $settings['out_of_stock'];
            $added = self::protectCustomProducts($idManufacturer, $mode, false);
        }

        return array('added' => $added, 'exceptions' => count(self::getExceptionIds($idManufacturer)));
    }

    /**
     * Product ids of the manufacturer that are NOT exceptions.
     */
    public static function getManagedProductIds($idManufacturer)
    {
        $idManufacturer = (int) $idManufacturer;
        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p
             WHERE p.`id_manufacturer` = ' . $idManufacturer . '
             AND p.`id_product` NOT IN (
                SELECT e.`id_product` FROM `' . _DB_PREFIX_ . 'mfa_exception` e WHERE e.`id_manufacturer` = ' . $idManufacturer . '
             )'
        );
        $ids = array();
        foreach ((array) $rows as $r) {
            $ids[] = (int) $r['id_product'];
        }

        return $ids;
    }

    public static function applyManufacturer($idManufacturer)
    {
        $settings = self::getSettings($idManufacturer);
        $ids = self::getManagedProductIds($idManufacturer);
        self::writeProducts($ids, $settings['out_of_stock'], self::resolveLabels($idManufacturer));

        return count($ids);
    }

    public static function resetManufacturer($idManufacturer, $restoreStock = true)
    {
        $ids = self::getManagedProductIds($idManufacturer);
        self::writeProducts($ids, $restoreStock ? self::MODE_DEFAULT : self::MODE_KEEP, self::emptyLabels());

        return count($ids);
    }

    protected static function emptyLabels()
    {
        $out = array();
        foreach (Language::getLanguages(false) as $lang) {
            $out[(int) $lang['id_lang']] = '';
        }

        return $out;
    }

    /**
     * Applies the module rules to one product (used by product save hooks).
     */
    public static function applyToProduct($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return false;
        }

        $idManufacturer = (int) Db::getInstance()->getValue(
            'SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct
        );
        if ($idManufacturer <= 0) {
            return false;
        }

        $map = self::getActiveMap();
        if (!isset($map[$idManufacturer])) {
            return false;
        }

        $isException = (bool) Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'mfa_exception`
             WHERE `id_manufacturer` = ' . $idManufacturer . ' AND `id_product` = ' . $idProduct
        );
        if ($isException) {
            return false;
        }

        self::writeProducts(array($idProduct), $map[$idManufacturer], self::resolveLabels($idManufacturer));
        ManufacturerAvailabilityCompat::cleanCaches();

        return true;
    }

    /**
     * Writes "out of stock" behaviour (stock_available.out_of_stock) and the label
     * (product_lang.available_later) for the given products.
     *
     * @param int[] $ids
     * @param int   $mode
     * @param array $labels id_lang => text
     */
    protected static function writeProducts(array $ids, $mode, array $labels)
    {
        if (empty($ids)) {
            return;
        }
        $mode = (int) $mode;
        if ($mode < 0 || $mode > 3) {
            $mode = self::MODE_KEEP;
        }

        $db = Db::getInstance();
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            if ($mode !== self::MODE_KEEP) {
                $db->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'stock_available` SET `out_of_stock` = ' . $mode . '
                     WHERE `id_product` IN (' . $in . ')'
                );
            }
            foreach ($labels as $idLang => $text) {
                $db->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'product_lang` SET `available_later` = \'' . pSQL($text) . '\'
                     WHERE `id_lang` = ' . (int) $idLang . ' AND `id_product` IN (' . $in . ')'
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Exceptions                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Replaces the exception list of a manufacturer. Products removed from the list
     * receive the manufacturer settings right away (when the manufacturer is active).
     *
     * @return array array('count' => int, 'updated' => int)
     */
    public static function saveExceptions($idManufacturer, array $ids)
    {
        $idManufacturer = (int) $idManufacturer;
        $db = Db::getInstance();

        $candidates = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $candidates[$id] = $id;
            }
        }

        $valid = array();
        foreach (array_chunk(array_values($candidates), self::CHUNK_SIZE) as $chunk) {
            $rows = $db->executeS(
                'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product`
                 WHERE `id_manufacturer` = ' . $idManufacturer . ' AND `id_product` IN (' . implode(',', $chunk) . ')'
            );
            foreach ((array) $rows as $r) {
                $valid[] = (int) $r['id_product'];
            }
        }

        $old = self::getExceptionIds($idManufacturer);
        $removed = array_values(array_diff($old, $valid));

        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'mfa_exception` WHERE `id_manufacturer` = ' . $idManufacturer);
        foreach (array_chunk($valid, self::CHUNK_SIZE) as $chunk) {
            $values = array();
            foreach ($chunk as $idProduct) {
                $values[] = '(' . $idManufacturer . ', ' . (int) $idProduct . ')';
            }
            $db->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mfa_exception` (`id_manufacturer`, `id_product`) VALUES ' . implode(',', $values)
            );
        }

        $updated = 0;
        $settings = self::getSettings($idManufacturer);
        if (!empty($removed) && !empty($settings['active'])) {
            self::writeProducts($removed, $settings['out_of_stock'], self::resolveLabels($idManufacturer));
            $updated = count($removed);
            ManufacturerAvailabilityCompat::cleanCaches();
        }

        return array('count' => count($valid), 'updated' => $updated);
    }

    public static function clearExceptions($idManufacturer)
    {
        return self::saveExceptions($idManufacturer, array());
    }

    /* ------------------------------------------------------------------ */
    /* Availability label templates (dropdown list)                       */
    /* ------------------------------------------------------------------ */

    public static function getDefaultPresets()
    {
        $templates = array(
            'en' => 'Availability %r% days',
            'pl' => 'Dostępność %r% dni',
            'de' => 'Verfügbarkeit %r% Tage',
            'fr' => 'Disponibilité %r% jours',
        );
        $out = array();
        foreach (array('1-3', '3-5', '7-10', '10-14', '14-21', '21-30') as $range) {
            $texts = array();
            foreach ($templates as $iso => $template) {
                $texts[$iso] = str_replace('%r%', $range, $template);
            }
            $out[] = array('key' => 'd' . $range, 'texts' => $texts);
        }

        return $out;
    }

    /**
     * @return array list of array('key' => string, 'texts' => array(iso => text))
     */
    public static function getPresets()
    {
        $raw = Configuration::getGlobalValue(self::CONFIG_PRESETS);
        if ($raw === false || $raw === null || $raw === '') {
            return self::getDefaultPresets();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::getDefaultPresets();
        }

        $out = array();
        foreach ($decoded as $preset) {
            if (!is_array($preset) || empty($preset['key']) || empty($preset['texts']) || !is_array($preset['texts'])) {
                continue;
            }
            $out[] = array('key' => (string) $preset['key'], 'texts' => $preset['texts']);
        }

        return $out;
    }

    protected static function savePresets(array $presets)
    {
        Configuration::updateGlobalValue(self::CONFIG_PRESETS, json_encode(array_values($presets)));
    }

    /**
     * @param array $texts iso code => text
     *
     * @return array|false the new list of presets, false when there is no text at all
     */
    public static function addPreset(array $texts)
    {
        $clean = array();
        foreach ($texts as $iso => $text) {
            $iso = strtolower((string) $iso);
            if (!preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $iso)) {
                continue;
            }
            $text = self::sanitizeLabel($text);
            if ($text !== '') {
                $clean[$iso] = $text;
            }
        }
        if (empty($clean)) {
            return false;
        }

        $presets = self::getPresets();
        foreach ($presets as $preset) {
            if ($preset['texts'] == $clean) {
                return $presets; // already on the list
            }
        }
        if (count($presets) >= self::MAX_PRESETS) {
            array_shift($presets);
        }

        $presets[] = array('key' => 'c' . substr(md5(uniqid('', true)), 0, 10), 'texts' => $clean);
        self::savePresets($presets);

        return $presets;
    }

    public static function deletePreset($key)
    {
        $presets = array();
        foreach (self::getPresets() as $preset) {
            if ($preset['key'] !== (string) $key) {
                $presets[] = $preset;
            }
        }
        self::savePresets($presets);

        return $presets;
    }

    /**
     * Update an existing availability label.
     *
     * @param string $key
     * @param array  $texts iso code => text
     *
     * @return array|false
     */
    public static function updatePreset($key, array $texts)
    {
        $key = (string) $key;
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $key)) {
            return false;
        }

        $clean = array();
        foreach ($texts as $iso => $text) {
            $iso = strtolower((string) $iso);
            if (!preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $iso)) {
                continue;
            }
            $text = self::sanitizeLabel($text);
            if ($text !== '') {
                $clean[$iso] = $text;
            }
        }
        if (empty($clean)) {
            return false;
        }

        $presets = self::getPresets();
        $found = false;
        foreach ($presets as $index => $preset) {
            if ($preset['key'] === $key) {
                $found = true;
                $presets[$index]['texts'] = $clean;
                break;
            }
        }
        if (!$found) {
            return false;
        }

        foreach ($presets as $preset) {
            if ($preset['key'] !== $key && $preset['texts'] == $clean) {
                return false;
            }
        }

        self::savePresets($presets);

        return $presets;
    }

    /* ------------------------------------------------------------------ */
    /* Display settings ("Configuration" tab)                             */
    /* ------------------------------------------------------------------ */

    public static function getUiSettingKeys()
    {
        return array('show_id', 'show_logo', 'show_name', 'show_exc_text', 'show_label_text');
    }

    public static function getUiSettings()
    {
        $settings = array(
            'show_id' => 1,
            'show_logo' => 1,
            'show_name' => 1,
            'show_exc_text' => 0,
            'show_label_text' => 0,
        );
        $raw = Configuration::getGlobalValue(self::CONFIG_SETTINGS);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach ($settings as $key => $default) {
                if (isset($decoded[$key])) {
                    $settings[$key] = (int) (bool) $decoded[$key];
                }
            }
        }

        return $settings;
    }

    public static function saveUiSettings(array $input)
    {
        $settings = array();
        foreach (self::getUiSettingKeys() as $key) {
            $settings[$key] = !empty($input[$key]) ? 1 : 0;
        }
        Configuration::updateGlobalValue(self::CONFIG_SETTINGS, json_encode($settings));

        return $settings;
    }

    public static function deleteManufacturerData($idManufacturer)
    {
        $idManufacturer = (int) $idManufacturer;
        $db = Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'mfa_manufacturer` WHERE `id_manufacturer` = ' . $idManufacturer);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'mfa_manufacturer_lang` WHERE `id_manufacturer` = ' . $idManufacturer);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'mfa_exception` WHERE `id_manufacturer` = ' . $idManufacturer);
        self::$activeMap = null;
    }
}
