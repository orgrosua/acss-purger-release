<?php

/*
 * This file is part of the Yabe package.
 *
 * (c) Joshua <id@rosua.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare (strict_types=1);
namespace Yabe\AcssPurger\Core;

use Automatic_CSS\Model\Config\Classes;
use _YabeAcssPurger\Sabberworm\CSS\CSSList\CSSList;
use _YabeAcssPurger\Sabberworm\CSS\CSSList\Document;
use _YabeAcssPurger\Sabberworm\CSS\Parser;
use _YabeAcssPurger\Sabberworm\CSS\RuleSet\DeclarationBlock;
use _YabeAcssPurger\Symfony\Component\Finder\Finder;
use Yabe\AcssPurger\Plugin;
use Yabe\AcssPurger\Utils\Common;
use Yabe\AcssPurger\Utils\Notice;
/**
 * Manage the cache of fonts for the frontpage.
 *
 * @author Joshua <id@rosua.org>
 */
class Cache
{
    /**
     * @var string
     */
    public const CACHE_DIR = '/acss-purger/cache/';
    public function __construct()
    {
        \add_filter('cron_schedules', fn($schedules) => $this->filter_cron_schedules($schedules));
        \add_action('a!yabe/acsspurger/core/cache:build_cache', fn() => $this->build_cache());
        // listen to Config change for cache build (async/scheduled)
        \add_action('f!yabe/acsspurger/api/setting/option:after_store', fn() => $this->schedule_cache(), 10, 1);
    }
    public function filter_cron_schedules($schedules)
    {
        $schedules['acss_purger_cache'] = ['interval' => \MINUTE_IN_SECONDS, 'display' => \__('Every minute', 'acss-purger')];
        return $schedules;
    }
    public function schedule_cache()
    {
        if (!\wp_next_scheduled('a!yabe/acsspurger/core/cache:build_cache')) {
            \wp_schedule_single_event(\time() + 10, 'a!yabe/acsspurger/core/cache:build_cache');
        }
    }
    public static function get_cache_path(string $file_path = '') : string
    {
        return \wp_upload_dir()['basedir'] . self::CACHE_DIR . $file_path;
    }
    public static function get_cache_url(string $file_path = '') : string
    {
        return \wp_upload_dir()['baseurl'] . self::CACHE_DIR . $file_path;
    }
    public function build_cache()
    {
        $acss_plugin_info = \get_plugin_data(\ACSS_PLUGIN_FILE);
        if (!\file_exists(\ACSS_DYNAMIC_CSS_DIR)) {
            return;
        }
        $finder = new Finder();
        $finder->files()->in(\ACSS_DYNAMIC_CSS_DIR)->name('*.css');
        $classes = (new Classes())->load();
        $selectors = \apply_filters('f!yabe/acsspurger/core/cache:selectors', []);
        $classes = \array_diff($classes, $selectors);
        $classes = \array_map(static fn($class) => \sprintf('.%s', $class), $classes);
        foreach ($finder as $file) {
            $raw = \file_get_contents($file->getRealPath());
            $purged = self::purge_css($raw, $classes);
            $payload = \sprintf("/*\n! %s v%s | %s v%s | %s\n*/\n\n%s", Common::plugin_data('Name'), Plugin::VERSION, $acss_plugin_info['Name'], $acss_plugin_info['Version'], \date('Y-m-d H:i:s', \time()), $purged);
            try {
                Common::save_file($payload, self::get_cache_path($file->getFilename()));
            } catch (\Throwable $throwable) {
                Notice::error(\sprintf('Failed to purge CSS: %s', $throwable->getMessage()));
            }
        }
    }
    /**
     * @param string $raw the CSS content to purge
     * @param array<string> $selectors the substring of selectors to match
     */
    public static function purge_css($raw, $selectors) : string
    {
        $parser = new Parser($raw);
        $cssDocument = $parser->parse();
        static::removeDeclarationBlockBySelector($cssDocument, $selectors);
        $css = $cssDocument->render();
        // remove new lines
        $css = \preg_replace('#\\n#', '', $css);
        // remove tabs
        $css = \preg_replace('#\\t#', '', $css);
        // remove multiple spaces
        $css = \preg_replace('#\\s+#', ' ', $css);
        return $css;
    }
    /**
     * Removes a declaration block from the CSS list if it contain any given sub-string of selectors.
     *
     * @param Document $document the document to remove the declaration block from
     * @param array<string> $mSelectors the substring of selectors to match
     *
     * @see \Sabberworm\CSS\CSSList\CSSList::removeDeclarationBlockBySelector()
     */
    public static function removeDeclarationBlockBySelector(&$document, $mSelectors)
    {
        foreach ($document->getContents() as $iKey => &$mItem) {
            if (!$mItem instanceof DeclarationBlock) {
                if ($mItem instanceof CSSList) {
                    static::removeDeclarationBlockBySelector($mItem, $mSelectors);
                    if ($mItem->getContents() === []) {
                        $document->remove($mItem);
                    }
                }
                continue;
            }
            $itemSelectors = $mItem->getSelectors();
            foreach ($mSelectors as $mSelector) {
                foreach ($itemSelectors as $itemSelector) {
                    if (\strpos($itemSelector->getSelector(), $mSelector) !== \false) {
                        $mItem->removeSelector($itemSelector);
                    }
                }
            }
            if ($mItem->getSelectors() === []) {
                $document->remove($mItem);
            }
        }
    }
}
