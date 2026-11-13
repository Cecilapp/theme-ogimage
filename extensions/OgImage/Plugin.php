<?php

namespace OgImage;

use Cecil\Plugin\AbstractPlugin;
use Cecil\Plugin\Registry;

/**
 * Open Graph image plugin: registers the `og_image()` Twig function and its default options.
 */
class Plugin extends AbstractPlugin
{
    public function register(Registry $registry): void
    {
        $registry
            // default options (can be overridden by the site, or by a theme requiring this one)
            ->config([
                'ogimage' => [
                    'enabled'    => true,
                    'sections'   => [],
                    'background' => 'ogimage/background.png',
                    'color'      => '#111111',
                    'fonts'      => [
                        'regular' => 'ogimage/fonts/GoogleSansCode-Regular.ttf',
                        'bold'    => 'ogimage/fonts/GoogleSansCode-Bold.ttf',
                    ],
                    'padding' => [
                        'top'    => 105,
                        'right'  => 110,
                        'bottom' => 100,
                        'left'   => 110,
                    ],
                    'by'         => 'by',
                    'site_title' => true,
                ],
            ])
            ->twigExtension(Twig\Extension::class, name: 'OgImage');
    }
}
