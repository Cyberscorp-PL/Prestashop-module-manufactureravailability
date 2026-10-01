<?php
/**
 * Version detection and compatibility helpers.
 *
 * Supported: PHP 8.0 - 8.5, PrestaShop 1.7.0 - 9.2.
 * The code deliberately avoids syntax that requires a newer PHP than the host
 * PrestaShop itself supports (no "??", no typed properties, no attributes).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ManufacturerAvailabilityCompat
{
    const PHP_MIN = '8.0.0';
    const PHP_MAX = '8.5.99';
    const PS_MIN = '1.7.0.0';
    const PS_MAX = '9.2.99';

    const TRANS_DOMAIN = 'Modules.Manufactureravailability.Admin';
    const LEGACY_SOURCE = 'manufactureravailability';

    public static function phpAtLeast($version)
    {
        return version_compare(PHP_VERSION, $version, '>=');
    }

    public static function psAtLeast($version)
    {
        return version_compare(_PS_VERSION_, $version, '>=');
    }

    public static function isPhpSupported()
    {
        return self::phpAtLeast(self::PHP_MIN) && version_compare(PHP_VERSION, self::PHP_MAX, '<=');
    }

    public static function isPsSupported()
    {
        return self::psAtLeast(self::PS_MIN) && version_compare(_PS_VERSION_, self::PS_MAX, '<=');
    }

    /**
     * Symfony translator with module domains is available since PrestaShop 1.7.6.
     */
    public static function hasSymfonyTranslator()
    {
        return self::psAtLeast('1.7.6.0');
    }

    /**
     * The new (Symfony based) product page appeared as opt-in in 8.1 and is the only one in 9.x.
     * It uses different hooks than the legacy AdminProducts controller.
     */
    public static function hasNewProductPage()
    {
        return self::psAtLeast('8.1.0');
    }

    /** @var array iso => (key => translation) */
    protected static $bundled = array();

    /**
     * Translate a string using the mechanism that matches the running PrestaShop
     * (Symfony translator or legacy l()). When PrestaShop has no translation for the
     * current back office language, the dictionary shipped in translations/<iso>.php is used.
     *
     * @param Module $module
     * @param string $string English source string
     * @param array  $params placeholders, e.g. array('%count%' => 5)
     *
     * @return string
     */
    public static function translate($module, $string, $params = array())
    {
        $translated = $string;

        try {
            if (self::hasSymfonyTranslator() && method_exists($module, 'trans')) {
                $translated = $module->trans($string, array(), self::TRANS_DOMAIN);
            } else {
                $translated = html_entity_decode(
                    $module->l($string, self::LEGACY_SOURCE),
                    ENT_QUOTES,
                    'UTF-8'
                );
            }
        } catch (Exception $e) {
            $translated = $string;
        } catch (Throwable $e) {
            $translated = $string;
        }

        if (!is_string($translated) || $translated === '' || $translated === $string) {
            $bundled = self::lookupBundled($string);
            $translated = ($bundled !== null) ? $bundled : $string;
        }

        return empty($params) ? $translated : strtr($translated, $params);
    }

    /**
     * Looks the string up in translations/<iso>.php (legacy format) for the current language.
     *
     * @return string|null
     */
    protected static function lookupBundled($string)
    {
        $context = Context::getContext();
        if (!is_object($context->language) || empty($context->language->iso_code)) {
            return null;
        }
        $iso = strtolower($context->language->iso_code);
        if (!preg_match('/^[a-z]{2,3}$/', $iso)) {
            return null;
        }

        if (!isset(self::$bundled[$iso])) {
            self::$bundled[$iso] = array();
            $file = dirname(dirname(__FILE__)) . '/translations/' . $iso . '.php';
            if (is_file($file)) {
                $hadGlobal = array_key_exists('_MODULE', $GLOBALS);
                $backup = $hadGlobal ? $GLOBALS['_MODULE'] : null;
                $GLOBALS['_MODULE'] = array();
                include $file;
                if (isset($GLOBALS['_MODULE']) && is_array($GLOBALS['_MODULE'])) {
                    self::$bundled[$iso] = $GLOBALS['_MODULE'];
                }
                if ($hadGlobal) {
                    $GLOBALS['_MODULE'] = $backup;
                } else {
                    unset($GLOBALS['_MODULE']);
                }
            }
        }

        $key = '<{' . self::LEGACY_SOURCE . '}prestashop>' . self::LEGACY_SOURCE . '_' . md5($string);

        return isset(self::$bundled[$iso][$key]) ? self::$bundled[$iso][$key] : null;
    }

    public static function cleanCaches()
    {
        if (class_exists('Cache')) {
            Cache::clean('StockAvailable::*');
            Cache::clean('objectmodel_Product_*');
        }
    }

    public static function getAjaxUrl()
    {
        return Context::getContext()->link->getAdminLink('AdminManufacturerAvailabilityAjax');
    }

    /**
     * Finds the manufacturer logo file in img/m/ (original first, then the largest generated variant).
     *
     * @return string absolute path or empty string
     */
    public static function findManufacturerLogoFile($idManufacturer)
    {
        $id = (int) $idManufacturer;
        $dir = _PS_MANU_IMG_DIR_;

        foreach (array('jpg', 'jpeg', 'png', 'gif', 'webp') as $ext) {
            $file = $dir . $id . '.' . $ext;
            if (is_file($file) && filesize($file) > 0) {
                return $file;
            }
        }

        $best = '';
        $bestSize = 0;
        $variants = glob($dir . $id . '-*.*');
        if (is_array($variants)) {
            foreach ($variants as $file) {
                if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $file)) {
                    continue;
                }
                $size = (int) @filesize($file);
                if ($size > 0 && $size >= $bestSize) {
                    $best = $file;
                    $bestSize = $size;
                }
            }
        }

        return $best;
    }

    /**
     * Reads the logo and returns it scaled down to $maxWidth px (never upscaled).
     *
     * @return array|null array(mime, bytes)
     */
    public static function buildLogoResponse($file, $maxWidth = 200)
    {
        $bytes = @file_get_contents($file);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $mime = ($info && !empty($info['mime'])) ? $info['mime'] : 'image/jpeg';

        if ($info && (int) $info[0] > (int) $maxWidth && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($bytes);
            if ($src) {
                $isJpeg = ((int) $info[2] === IMAGETYPE_JPEG);
                $w = imagesx($src);
                $h = imagesy($src);
                $newH = max(1, (int) round($h * $maxWidth / $w));
                $dst = imagecreatetruecolor((int) $maxWidth, $newH);
                if (!$isJpeg) {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, (int) $maxWidth, $newH, $w, $h);
                ob_start();
                if ($isJpeg) {
                    imagejpeg($dst, null, 85);
                } else {
                    imagepng($dst, null, 6);
                }
                $out = ob_get_clean();
                if (is_string($out) && $out !== '') {
                    return array($isJpeg ? 'image/jpeg' : 'image/png', $out);
                }
            }
        }

        return array($mime, $bytes);
    }

    /**
     * Logo thumbnail (max $maxWidth px wide) as a data: URI, so no extra HTTP request,
     * URL, token or writable image directory is needed. The result is cached in var/cache.
     *
     * @return string data URI or empty string when the manufacturer has no logo
     */
    public static function getManufacturerLogoDataUri($idManufacturer, $maxWidth = 200)
    {
        $file = self::findManufacturerLogoFile($idManufacturer);
        if ($file === '') {
            return '';
        }

        $mtime = (int) @filemtime($file);
        $cacheDir = _PS_CACHE_DIR_ . 'mfa_logo/';
        $cacheFile = $cacheDir . (int) $idManufacturer . '_' . $mtime . '_' . (int) $maxWidth . '.txt';

        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && strpos($cached, 'data:image/') === 0) {
                return $cached;
            }
        }

        $image = self::buildLogoResponse($file, $maxWidth);
        if ($image === null) {
            return '';
        }
        $uri = 'data:' . $image[0] . ';base64,' . base64_encode($image[1]);

        if (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) {
            @file_put_contents($cacheFile, $uri);
        }

        return $uri;
    }

    /**
     * Number of logo image files (ID.ext) found in img/m/ - used on the Information tab.
     */
    public static function countManufacturerLogoFiles()
    {
        $count = 0;
        $files = @scandir(_PS_MANU_IMG_DIR_);
        if (is_array($files)) {
            foreach ($files as $file) {
                if (preg_match('/^[0-9]+\.(jpe?g|png|gif|webp)$/i', $file)) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    public static function isGdAvailable()
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }
}
