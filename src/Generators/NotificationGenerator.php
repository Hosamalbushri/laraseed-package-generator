<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Support\Str;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class NotificationGenerator
{
    protected string $basePath;

    /**
     * Supported notification channels.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_CHANNELS = [
        'mail',
        'database',
        'broadcast',
    ];

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
     * Generate a Notification class for the specified package.
     *
     * @param  array<int, string>  $channels
     * @return array{
     *     package: ResolvedPackage,
     *     notification: string,
     *     channels: array<int, string>,
     *     queued: bool,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $notificationName,
        array $channels = ['mail'],
        bool $queued = false,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateNotificationName($notificationName);
        $normalizedChannels = $this->normalizeChannels($channels);

        $subject = Str::headline($notificationName);
        $viaChannelsCode = "['" . implode("', '", $normalizedChannels) . "']";

        $methodsCode = $this->buildChannelMethods($normalizedChannels, $subject);
        $importsCode = $this->buildImports($normalizedChannels);

        $stub = $this->renderer->render('notification.php.stub', $resolved->identity);
        $content = str_replace(
            [
                '{{ NAMESPACE }}',
                '{{ IMPORTS }}',
                '{{ NOTIFICATION_CLASS }}',
                '{{ SUBJECT }}',
                '{{ QUEUE_INTERFACE }}',
                '{{ VIA_CHANNELS }}',
                '{{ CHANNEL_METHODS }}',
            ],
            [
                $resolved->namespace,
                $importsCode,
                $notificationName,
                $subject,
                $queued ? ' implements ShouldQueue' : '',
                $viaChannelsCode,
                $methodsCode,
            ],
            $stub
        );

        $relativePath = "src/Notifications/{$notificationName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package'      => $resolved,
            'notification' => $notificationName,
            'channels'     => $normalizedChannels,
            'queued'       => $queued,
            'dry_run'      => $dryRun,
            'force'        => $force,
            'files'        => $results,
        ];
    }

    /**
     * Build import statements for the notification.
     *
     * @param  array<int, string>  $channels
     */
    protected function buildImports(array $channels): string
    {
        $imports = [
            'use Illuminate\Bus\Queueable;',
            'use Illuminate\Contracts\Queue\ShouldQueue;',
        ];

        if (in_array('mail', $channels, true)) {
            $imports[] = 'use Illuminate\Notifications\Messages\MailMessage;';
        }

        $imports[] = 'use Illuminate\Notifications\Notification;';

        return implode("\n", $imports) . "\n";
    }

    /**
     * Build channel-specific methods for the notification.
     *
     * @param  array<int, string>  $channels
     */
    protected function buildChannelMethods(array $channels, string $subject): string
    {
        $methods = [];

        if (in_array('mail', $channels, true)) {
            $methods[] = <<<PHP

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object \$notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('{$subject}')
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }
PHP;
        }

        if (in_array('database', $channels, true) || in_array('broadcast', $channels, true)) {
            $methods[] = <<<PHP

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object \$notifiable): array
    {
        return [
            'title'      => '{$subject}',
            'message'    => 'Notification content for {$subject}.',
            'action_url' => url('/'),
        ];
    }
PHP;
        }

        return implode("\n", $methods);
    }

    protected function validateNotificationName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Notification class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Notification class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Notification class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function normalizeChannels(array $channels): array
    {
        if ($channels === []) {
            return ['mail'];
        }

        $normalized = [];
        foreach ($channels as $channel) {
            $ch = strtolower(trim($channel));
            if ($ch === '') {
                continue;
            }

            if (! in_array($ch, self::SUPPORTED_CHANNELS, true)) {
                $available = implode(', ', self::SUPPORTED_CHANNELS);
                throw PackageGenerationException::invalidInput("Invalid notification channel [{$ch}]. Supported channels: {$available}.");
            }

            if (! in_array($ch, $normalized, true)) {
                $normalized[] = $ch;
            }
        }

        return $normalized !== [] ? $normalized : ['mail'];
    }
}
