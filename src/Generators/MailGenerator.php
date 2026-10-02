<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Support\Str;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class MailGenerator
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
     * Generate a Mailable class and companion Blade template for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     mail: string,
     *     view: string,
     *     is_markdown: bool,
     *     queued: bool,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $mailName,
        ?string $view = null,
        ?string $markdown = null,
        bool $queued = false,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateMailName($mailName);

        $isMarkdown = $markdown !== null;
        if ($isMarkdown) {
            $viewDotPath = $markdown !== '' ? $markdown : 'emails.' . Str::kebab($mailName);
        } else {
            $viewDotPath = ($view !== null && $view !== '') ? $view : 'emails.' . Str::kebab($mailName);
        }

        $this->validateViewName($viewDotPath);

        $subject = Str::headline($mailName);
        $viewIdentifier = "{$resolved->identity->packageSnake}::{$viewDotPath}";
        $viewRelativePath = 'src/Resources/views/' . str_replace('.', '/', $viewDotPath) . '.blade.php';

        // 1. Render Mailable class
        $mailStub = $this->renderer->render('mail.php.stub', $resolved->identity);
        $mailContent = str_replace(
            [
                '{{ NAMESPACE }}',
                '{{ MAIL_CLASS }}',
                '{{ SUBJECT }}',
                '{{ CONTENT_TYPE }}',
                '{{ VIEW_NAME }}',
                '{{ QUEUE_INTERFACE }}',
            ],
            [
                $resolved->namespace,
                $mailName,
                $subject,
                $isMarkdown ? 'markdown' : 'view',
                $viewIdentifier,
                $queued ? ' implements ShouldQueue' : '',
            ],
            $mailStub
        );

        // 2. Render companion Blade view
        $viewStubFile = $isMarkdown ? 'mail_markdown_view.blade.php.stub' : 'mail_html_view.blade.php.stub';
        $viewStub = $this->renderer->render($viewStubFile, $resolved->identity);
        $viewContent = str_replace('{{ SUBJECT }}', $subject, $viewStub);

        $files = [
            "src/Mail/{$mailName}.php" => $mailContent,
            $viewRelativePath         => $viewContent,
        ];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package'     => $resolved,
            'mail'        => $mailName,
            'view'        => $viewDotPath,
            'is_markdown' => $isMarkdown,
            'queued'      => $queued,
            'dry_run'     => $dryRun,
            'force'       => $force,
            'files'       => $results,
        ];
    }

    protected function validateMailName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Mailable class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Mailable class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Mailable class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }

    protected function validateViewName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Mail view name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Mail view name contains path traversal or invalid path characters.');
        }

        if (str_starts_with($trimmed, '.') || str_ends_with($trimmed, '.')) {
            throw PackageGenerationException::invalidInput("Mail view name [{$trimmed}] cannot start or end with a dot.");
        }

        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Mail view name [{$trimmed}] contains invalid characters.");
        }
    }
}
