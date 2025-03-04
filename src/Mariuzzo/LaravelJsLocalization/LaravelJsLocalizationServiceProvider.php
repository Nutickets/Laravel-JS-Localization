<?php

namespace Mariuzzo\LaravelJsLocalization;

use Illuminate\Support\ServiceProvider;

/**
 * The LaravelJsLocalizationServiceProvider class.
 *
 * @author  Rubens Mariuzzo <rubens@mariuzzo.com>
 */
class LaravelJsLocalizationServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * The path of this package configuration file.
     *
     * @var string
     */
    protected $configPath;

    public function __constructor()
    {
        parent::__constructor();
        $this->configPath = $this->app['path.config'].DIRECTORY_SEPARATOR;
    }

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        $configPath = __DIR__.'/../../config/config.php';
        $configKey = 'localization-js';

        // Determines Laravel major version.
        $app = $this->app;
        $laravelMajorVersion = (int) $app::VERSION;

        // Publishes Laravel-JS-Localization package files and merge user and
        // package configurations.
        if ($laravelMajorVersion === 4) {
            $config = $this->app['config']->get($configKey, []);
            $this->app['config']->set($configKey, array_merge(require $configPath, $config));
        } elseif ($laravelMajorVersion >= 5) {
            $this->publishes([
                $configPath => config_path("$configKey.php"),
            ]);
            $this->mergeConfigFrom(
                $configPath, $configKey
            );
        }
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        // Bind the Laravel JS Localization command into the app IOC.
        $this->app->singleton('localization.js', function ($app) {
            $app = $this->app;
            $laravelMajorVersion = (int) $app::VERSION;

            $files = $app['files'];

            if ($laravelMajorVersion === 4) {
                $langs = $app['path.base'].'/app/lang';
            } elseif ($laravelMajorVersion >= 9) {
                $langs = $app['path.base'].'/lang';
            } elseif ($laravelMajorVersion >= 5) {
                $langs = $app['path.base'].'/resources/lang';
            }

            /**
             * A hotfix for the original code assuming that the `lang` directory will be moved up a level out of the
             * `resources` directory. There are a few PRs / issues on the upstream repo that try to address this,
             * however for now none have been merged. This can likely just be removed once they're merged.
             * @see https://github.com/rmariuzzo/Laravel-JS-Localization/pull/201
             * @see https://github.com/rmariuzzo/Laravel-JS-Localization/pull/172/files
             * @see https://github.com/rmariuzzo/Laravel-JS-Localization/pull/203#issuecomment-2693795813
             */
            $langs = lang_path();

            $messages = $app['config']->get('localization-js.messages');
            $generator = new Generators\LangJsGenerator($files, $langs, $messages);

            return new Commands\LangJsCommand($generator);
        });

        // Bind the Laravel JS Localization command into Laravel Artisan.
        $this->commands('localization.js');
    }

    /**
     * Get the services provided by this provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['localization.js'];
    }
}
