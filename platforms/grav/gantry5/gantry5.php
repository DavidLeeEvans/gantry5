<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Gantry\Admin\Router;
use Gantry\Framework\Gantry;
use Gantry\Framework\Theme;
use Gantry5\Loader;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Twig\Twig;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

class Gantry5Plugin extends Plugin
{
    protected $base;
    protected $template;

    /**
     * @var Theme
     */
    protected $theme;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['initialize', 1000]
            ],
            'onThemeInitialized' => [
                ['initializeGantryTheme', -10]
            ],
        ];
    }

    public function initialize()
    {
        /** @var ClassLoader $loader */
        $loader = $this->grav['loader'];
        $loader->addClassMap(['Gantry5\\Loader' => __DIR__ . '/src/Loader.php']);
    }

    /**
     * Initialize administration plugin if admin path matches.
     *
     * Disables system cache.
     */
    public function initializeGantryTheme()
    {
        if (!class_exists('Gantry\Framework\Gantry')) {
            return;
        }

        $gantry = Gantry::instance();

        // Initialize theme stream.
        $gantry['platform']->set(
            'streams.gantry-theme.prefixes',
            ['' => [
                "gantry-themes://{$gantry['theme.name']}/custom",
                "gantry-themes://{$gantry['theme.name']}",
                "gantry-themes://{$gantry['theme.name']}/common"
            ]]
        );

        $gantry['streams'];

        /** @var \Gantry\Framework\Theme $theme */
        $theme = $gantry['theme'];
        $version = isset($this->grav['theme']->gantry) ? $this->grav['theme']->gantry : 0;

        if (!$gantry->isCompatible($version)) {
            $message = "Theme requires Gantry v{$version} (or later) in order to work! Please upgrade Gantry Framework.";
            if ($this->isAdmin()) {
                $messages = $this->grav['messages'];
                $messages->add($message, 'error');
                return;
            } else {
                throw new \LogicException($message);
            }
        }

        $this->theme = $theme;

        if (isset($this->grav['admin'])) {
            $this->enable([
                'onThemeInitialized' => [
                    ['detectGantryAdmin', -20]
                ],
            ]);
        } else {
            $this->enable([
                'onThemeInitialized' => [
                    ['detectGantrySite', -20]
                ],
            ]);
        }
    }

    public function detectGantrySite()
    {
        $this->theme->setLayout('default');

        $this->enable([
            'onTwigInitialized' => ['onThemeTwigInitialized', 0],
            'onTwigSiteVariables' => ['onThemeTwigVariables', 0]
        ]);
    }

        /**
     * Initialize administration plugin if admin path matches.
     *
     * Disables system cache.
     */
    public function detectGantryAdmin()
    {
        /** @var \Grav\Plugin\Admin $admin */
        $admin = $this->grav['admin'];
        if ($admin->location != 'themes' || !$admin->route) {
            return;
        }

        if (!defined('GANTRYADMIN_PATH')) {
            define('GANTRYADMIN_PATH', __DIR__);
        }

        $base = rtrim($this->grav['base_url'], '/');
        $results = explode('/', $admin->route, 3);
        $theme = array_shift($results);
        $this->template = array_shift($results) ?: 'about';
        $this->route = array_shift($results);
        $this->base =  "{$base}{$admin->base}/{$admin->location}/{$theme}";

        $this->config->set('system.pages.theme', $theme);

        $this->enable([
            'onThemeInitialized' => ['runAdmin', -30],
        ]);
    }

    public function runAdmin()
    {
        $gantry = Gantry::instance();
        $gantry['base_url'] = $this->base;
        $gantry['router'] = function ($c) {
            return new Router($c);
        };

        // Dispatch to the controller.
        //$gantry['router']->dispatch();

        $this->grav['gantry5'] = $gantry;

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 900],
            'onTwigInitialized' => ['onAdminTwigInitialized', 900],
            'onTwigSiteVariables' => ['onAdminTwigVariables', 900]
        ]);
    }

    /**
     * Replaces page object with admin one.
     */
    public function onPagesInitialized()
    {
        // Create admin page.
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . "/pages/gantry5.md"));
        $page->slug($this->template);
        $this->grav['page'] = $page;
    }


    /**
     * Add twig paths to plugin templates.
     */
    public function onAdminTwigInitialized()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $locator->addPath('gantry-admin', '', ['user/plugins/gantry5', 'user/plugins/gantry5/common']);
        $locator->addPath('gantry-admin', 'assets', ['user/plugins/gantry5/common']);

        $loader = $twig->loader();
        $loader->setPaths($locator->findResources('gantry-admin://templates'), 'gantry-admin');
    }

    /**
     * Set all twig variables for generating output.
     */
    public function onAdminTwigVariables()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        $twig->template = "@gantry-admin/pages/{$this->template}.html.twig";

        $twig->twig_vars['location'] = $this->template;
        $twig->twig_vars['gantry_url'] = $this->base;
    }

    /**
     * Initialize nucleus layout engine.
     */
    public function onThemeTwigInitialized()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $this->theme->extendTwig($twig->twig(), $twig->loader());
    }

    /**
     * Load current layout.
     */
    public function onThemeTwigVariables()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $twig->twig_vars = $this->theme->getContext($twig->twig_vars);
    }
}
