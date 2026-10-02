<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Support\Str;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class DataGridGenerator
{
    protected string $basePath;

    public function __construct(
        protected PackageResolver $resolver = new PackageResolver,
        protected StubRenderer $renderer = new StubRenderer,
        protected FilesystemWriter $writer = new FilesystemWriter,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->resolver = new PackageResolver(basePath: $this->basePath);
        $this->writer = new FilesystemWriter(basePath: $this->basePath);
    }

    /**
     * Generate a DataGrid class for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     datagrid: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $dataGridName,
        ?string $modelOption = null,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateDataGridName($dataGridName);

        $modelImport = '';
        $queryBuilder = "DB::table('table_name')->addSelect('id', 'created_at')";

        if ($modelOption !== null && $modelOption !== '') {
            $modelClass = class_basename(str_replace('/', '\\', $modelOption));
            $packageRoot = rtrim($this->basePath, '/') . '/' . $resolved->identity->relativePackagePath;
            $expectedModelFile = "{$packageRoot}/src/Models/{$modelClass}.php";
            $expectedModelClass = "{$resolved->namespace}\\Models\\{$modelClass}";

            if (! file_exists($expectedModelFile) && ! class_exists($expectedModelClass)) {
                throw PackageGenerationException::invalidInput("Model [{$modelOption}] does not exist in package [{$packageInput}] at [src/Models/{$modelClass}.php].");
            }

            $modelImport = "use {$expectedModelClass};";
            $tableName = Str::snake(Str::pluralStudly($modelClass));
            $queryBuilder = "DB::table('{$tableName}')->addSelect('id', 'created_at')";
        }

        $packageRoot = rtrim($this->basePath, '/') . '/' . $resolved->identity->relativePackagePath;
        $adminDir = "{$packageRoot}/src/Admin";

        if (is_dir($adminDir)) {
            $targetNamespace = "{$resolved->namespace}\\Admin\\DataGrids";
            $relativePath = "src/Admin/DataGrids/{$dataGridName}.php";
        } else {
            $targetNamespace = "{$resolved->namespace}\\DataGrids";
            $relativePath = "src/DataGrids/{$dataGridName}.php";
        }

        $content = $this->renderer->render('datagrid.php.stub', $resolved->identity);
        $content = str_replace(
            ['{{ DATAGRID_NAMESPACE }}', '{{ DATAGRID_CLASS }}', '{{ MODEL_IMPORT }}', '{{ QUERY_BUILDER }}'],
            [$targetNamespace, $dataGridName, $modelImport, $queryBuilder],
            $content
        );

        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'datagrid' => $dataGridName,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateDataGridName(string $name): void
    {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw PackageGenerationException::invalidInput("Invalid DataGrid class name [{$name}]. Must be a valid PHP class identifier in StudlyCase.");
        }
    }
}
