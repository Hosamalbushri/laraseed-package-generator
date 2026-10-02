<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class ListenerGenerator
{
    protected string $basePath;

    public function __construct(
        protected PackageResolver $resolver = new PackageResolver,
        protected StubRenderer $renderer = new StubRenderer,
        protected FilesystemWriter $writer = new FilesystemWriter,
        protected Filesystem $filesystem = new Filesystem,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->resolver = new PackageResolver(basePath: $this->basePath);
        $this->writer = new FilesystemWriter(basePath: $this->basePath);
    }

    /**
     * Generate an Event Listener class for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     listener: string,
     *     event: ?string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $listenerName,
        ?string $eventName = null,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateListenerName($listenerName);

        $eventImport = '';
        $eventTypehint = 'object';

        if ($eventName !== null && trim($eventName) !== '') {
            $trimmedEvent = trim($eventName);
            $this->validateEventName($trimmedEvent);

            $eventPath = "{$resolved->packagePath}/src/Events/{$trimmedEvent}.php";

            if (! $this->filesystem->exists($eventPath)) {
                throw PackageGenerationException::invalidInput("Event [{$trimmedEvent}] not found in package [{$packageInput}] at [src/Events/{$trimmedEvent}.php]. Generate the event first using laraseed:make-event.");
            }

            $eventImport = "use {$resolved->namespace}\\Events\\{$trimmedEvent};";
            $eventTypehint = $trimmedEvent;
        }

        $content = $this->renderer->render('listener.php.stub', $resolved->identity);
        $content = str_replace(
            ['{{ NAMESPACE }}', '{{ LISTENER_NAME }}', '{{ EVENT_IMPORT }}', '{{ EVENT_TYPEHINT }}'],
            [$resolved->namespace, $listenerName, $eventImport, $eventTypehint],
            $content
        );

        $relativePath = "src/Listeners/{$listenerName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'listener' => $listenerName,
            'event' => $eventName,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateListenerName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Listener class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Listener class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Listener class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }

    protected function validateEventName(string $name): void
    {
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw PackageGenerationException::invalidInput('Event class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw PackageGenerationException::invalidInput("Event class name [{$name}] must be a valid PHP class identifier.");
        }
    }
}
