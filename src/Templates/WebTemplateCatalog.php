<?php

namespace Laraseed\PackageGenerator\Templates;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;

class WebTemplateCatalog
{
    /**
     * Default template ID.
     */
    public const DEFAULT_TEMPLATE = 'starter';

    /**
     * Built-in default template definitions.
     *
     * @var array<string, array{id: string, name: string, description: string, files: array<string, string>}>
     */
    protected static array $defaultTemplates = [
        'starter' => [
            'id' => 'starter',
            'name' => 'Web Starter',
            'description' => 'Standard Web Starter template with Blade components, Tailwind CSS, Cairo typography, Vue 3, and package-isolated routes.',
            'files' => [
                'package.json'                                                      => 'templates/starter/package.json.stub',
                'vite.config.js'                                                    => 'templates/starter/vite.config.js.stub',
                'tailwind.config.js'                                                => 'templates/starter/tailwind.config.js.stub',
                'postcss.config.js'                                                 => 'templates/starter/postcss.config.js.stub',
                'src/Web/Providers/WebServiceProvider.php'                          => 'templates/starter/provider.php.stub',
                'src/Web/Config/web.php'                                            => 'templates/starter/config.php.stub',
                'src/Web/Http/Middleware/AuthenticateWeb.php'                       => 'templates/starter/middleware_auth.php.stub',
                'src/Web/Http/Controllers/HomeController.php'                       => 'templates/starter/controller_home.php.stub',
                'src/Web/Http/Controllers/PageController.php'                       => 'templates/starter/controller_page.php.stub',
                'src/Web/Http/Controllers/AccountController.php'                    => 'templates/starter/controller_account.php.stub',
                'src/Web/Routes/web.php'                                            => 'templates/starter/routes_web.php.stub',
                'src/Web/Resources/lang/en/app.php'                                 => 'templates/starter/lang_en.php.stub',
                'src/Web/Resources/lang/ar/app.php'                                 => 'templates/starter/lang_ar.php.stub',
                'src/Web/Resources/views/components/layouts/index.blade.php'         => 'templates/starter/layout.blade.php.stub',
                'src/Web/Resources/views/components/layouts/header/index.blade.php'  => 'templates/starter/header.blade.php.stub',
                'src/Web/Resources/views/components/layouts/header/navbar.blade.php' => 'templates/starter/navbar.blade.php.stub',
                'src/Web/Resources/views/components/layouts/footer/index.blade.php'  => 'templates/starter/footer.blade.php.stub',
                'src/Web/Resources/views/components/container/index.blade.php'       => 'templates/starter/component_container.blade.php.stub',
                'src/Web/Resources/views/components/section/index.blade.php'         => 'templates/starter/component_section.blade.php.stub',
                'src/Web/Resources/views/components/card/index.blade.php'            => 'templates/starter/component_card.blade.php.stub',
                'src/Web/Resources/views/components/button/index.blade.php'          => 'templates/starter/component_button.blade.php.stub',
                'src/Web/Resources/views/components/modal/index.blade.php'           => 'templates/starter/component_modal.blade.php.stub',
                'src/Web/Resources/views/components/form/control-group/index.blade.php' => 'templates/starter/component_form_control_group.blade.php.stub',
                'src/Web/Resources/views/home/index.blade.php'                      => 'templates/starter/view_home.blade.php.stub',
                'src/Web/Resources/views/pages/show.blade.php'                      => 'templates/starter/view_page.blade.php.stub',
                'src/Web/Resources/views/account/dashboard.blade.php'               => 'templates/starter/view_account_dashboard.blade.php.stub',
                'src/Web/Resources/assets/css/app.css'                              => 'templates/starter/asset_css.css.stub',
                'src/Web/Resources/assets/js/app.js'                               => 'templates/starter/asset_js.js.stub',
                'tests/Feature/Web/WebPageTest.php'                                 => 'templates/starter/feature_test.php.stub',
            ],
        ],
    ];

    /**
     * Registered Web templates metadata.
     *
     * @var array<string, array{id: string, name: string, description: string, files: array<string, string>}>
     */
    protected static array $templates = [];

    /**
     * Ensure the template catalog is initialized.
     */
    protected static function ensureInitialized(): void
    {
        if (empty(static::$templates)) {
            static::$templates = static::$defaultTemplates;
            static::registerFromConfig();
        }
    }

    /**
     * Register a new Web template definition into the catalog.
     *
     * @param  string  $id
     * @param  array{name: string, description?: string, files: array<string, string>}  $definition
     * @param  bool  $overwrite
     * @return void
     *
     * @throws PackageGenerationException
     */
    public static function register(string $id, array $definition, bool $overwrite = false): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $id)) {
            throw PackageGenerationException::invalidInput(
                "Invalid template ID [{$id}]. Template IDs may only contain lowercase alphanumeric characters, underscores, and dashes."
            );
        }

        static::ensureInitialized();

        if (isset(static::$templates[$id]) && ! $overwrite) {
            throw PackageGenerationException::invalidInput("Web template [{$id}] is already registered.");
        }

        if (empty($definition['name']) || ! is_string($definition['name'])) {
            throw PackageGenerationException::invalidInput("Template [{$id}] definition must include a non-empty string 'name'.");
        }

        if (isset($definition['description']) && ! is_string($definition['description'])) {
            throw PackageGenerationException::invalidInput("Template [{$id}] definition 'description' must be a string.");
        }

        if (empty($definition['files']) || ! is_array($definition['files'])) {
            throw PackageGenerationException::invalidInput("Template [{$id}] definition must include a non-empty array of 'files'.");
        }

        foreach ($definition['files'] as $dest => $stub) {
            if (! is_string($dest) || trim($dest) === '' || ! is_string($stub) || trim($stub) === '') {
                throw PackageGenerationException::invalidInput("Template [{$id}] files mapping must contain non-empty string keys and values.");
            }
        }

        static::$templates[$id] = [
            'id'          => $id,
            'name'        => $definition['name'],
            'description' => $definition['description'] ?? '',
            'files'       => $definition['files'],
        ];
    }

    /**
     * Unregister a template by ID.
     */
    public static function unregister(string $id): void
    {
        static::ensureInitialized();
        unset(static::$templates[$id]);
    }

    /**
     * Reset the catalog to default built-in templates.
     */
    public static function reset(): void
    {
        static::$templates = static::$defaultTemplates;
    }

    /**
     * Register templates defined in the host application configuration.
     */
    public static function registerFromConfig(): void
    {
        if (function_exists('config')) {
            $configTemplates = config('laraseed.web_templates', []);
            if (is_array($configTemplates)) {
                foreach ($configTemplates as $id => $def) {
                    if (is_string($id) && is_array($def)) {
                        static::register($id, $def, overwrite: true);
                    }
                }
            }
        }
    }

    /**
     * Get all registered templates.
     *
     * @return array<string, array{id: string, name: string, description: string, files: array<string, string>}>
     */
    public static function all(): array
    {
        static::ensureInitialized();

        return static::$templates;
    }

    /**
     * Get template IDs list.
     *
     * @return list<string>
     */
    public static function getAvailableTemplateIds(): array
    {
        static::ensureInitialized();

        return array_keys(static::$templates);
    }

    /**
     * Check if a template ID is registered.
     */
    public static function has(string $templateId): bool
    {
        static::ensureInitialized();

        return isset(static::$templates[$templateId]);
    }

    /**
     * Get a template definition by ID.
     *
     * @return array{id: string, name: string, description: string, files: array<string, string>}
     *
     * @throws PackageGenerationException
     */
    public static function get(string $templateId): array
    {
        static::ensureInitialized();

        if (! static::has($templateId)) {
            $available = implode(', ', static::getAvailableTemplateIds());
            throw PackageGenerationException::invalidInput(
                "Invalid template [{$templateId}]. Available templates: {$available}"
            );
        }

        return static::$templates[$templateId];
    }

    /**
     * Get default template ID.
     */
    public static function defaultTemplateId(): string
    {
        return static::DEFAULT_TEMPLATE;
    }
}
