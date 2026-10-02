<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Laraseed\PackageGenerator\Generators\FilesystemTransaction;
use Laraseed\PackageGenerator\Generators\GenerationPlan;
use Laraseed\PackageGenerator\Generators\MailGenerator;
use Laraseed\PackageGenerator\Tests\TestCase;

class MailGeneratorTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeTest\\')) {
                $relative = substr($class, strlen('AcmeTest\\'));
                $parts = explode('\\', $relative, 2);
                $package = $parts[0];
                $subPath = isset($parts[1]) ? str_replace('\\', '/', $parts[1]) : '';
                $path = base_path("packages/AcmeTest/{$package}/src/{$subPath}.php");
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirectories as $dir) {
            if ($this->filesystem->isDirectory($dir)) {
                $this->filesystem->deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    protected function trackDirectory(string $relativeOrAbsolutePath): string
    {
        $abs = str_starts_with($relativeOrAbsolutePath, '/')
            ? $relativeOrAbsolutePath
            : base_path($relativeOrAbsolutePath);

        if (! in_array($abs, $this->createdDirectories, true)) {
            $this->createdDirectories[] = $abs;
        }

        return $abs;
    }

    public function test_make_mail_generates_standard_mailable_and_companion_html_view(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailPkg WelcomeMail')->assertExitCode(0);

        $mailFile = "{$pkgDir}/src/Mail/WelcomeMail.php";
        $viewFile = "{$pkgDir}/src/Resources/views/emails/welcome-mail.blade.php";

        $this->assertTrue($this->filesystem->exists($mailFile));
        $this->assertTrue($this->filesystem->exists($viewFile));

        $mailContent = (string) file_get_contents($mailFile);
        $this->assertStringContainsString('namespace AcmeTest\MailPkg\Mail;', $mailContent);
        $this->assertStringContainsString('class WelcomeMail extends Mailable', $mailContent);
        $this->assertStringNotContainsString('implements ShouldQueue', $mailContent);
        $this->assertStringContainsString("subject: 'Welcome Mail'", $mailContent);
        $this->assertStringContainsString("view: 'mail_pkg::emails.welcome-mail'", $mailContent);

        $viewContent = (string) file_get_contents($viewFile);
        $this->assertStringContainsString('<h1>Welcome Mail</h1>', $viewContent);
    }

    public function test_make_mail_with_custom_view_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailCustomViewPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailCustomViewPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailCustomViewPkg OrderConfirmationMail --view=emails.orders.confirmation')
            ->assertExitCode(0);

        $mailFile = "{$pkgDir}/src/Mail/OrderConfirmationMail.php";
        $viewFile = "{$pkgDir}/src/Resources/views/emails/orders/confirmation.blade.php";

        $this->assertTrue($this->filesystem->exists($mailFile));
        $this->assertTrue($this->filesystem->exists($viewFile));

        $mailContent = (string) file_get_contents($mailFile);
        $this->assertStringContainsString("view: 'mail_custom_view_pkg::emails.orders.confirmation'", $mailContent);
    }

    public function test_make_mail_with_markdown_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailMarkdownPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailMarkdownPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailMarkdownPkg InvoiceReportMail --markdown=emails.finance.invoice')
            ->assertExitCode(0);

        $mailFile = "{$pkgDir}/src/Mail/InvoiceReportMail.php";
        $viewFile = "{$pkgDir}/src/Resources/views/emails/finance/invoice.blade.php";

        $this->assertTrue($this->filesystem->exists($mailFile));
        $this->assertTrue($this->filesystem->exists($viewFile));

        $mailContent = (string) file_get_contents($mailFile);
        $this->assertStringContainsString("markdown: 'mail_markdown_pkg::emails.finance.invoice'", $mailContent);

        $viewContent = (string) file_get_contents($viewFile);
        $this->assertStringContainsString('<x-mail::message>', $viewContent);
        $this->assertStringContainsString('# Invoice Report Mail', $viewContent);
    }

    public function test_make_mail_with_queued_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailQueuedPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailQueuedPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailQueuedPkg AsyncNotificationMail --queued')
            ->assertExitCode(0);

        $mailFile = "{$pkgDir}/src/Mail/AsyncNotificationMail.php";
        $this->assertTrue($this->filesystem->exists($mailFile));

        $mailContent = (string) file_get_contents($mailFile);
        $this->assertStringContainsString('class AsyncNotificationMail extends Mailable implements ShouldQueue', $mailContent);
    }

    public function test_make_mail_dry_run_creates_zero_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailDryRunPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailDryRunPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailDryRunPkg DryMail --dry-run')
            ->assertExitCode(0);

        $mailFile = "{$pkgDir}/src/Mail/DryMail.php";
        $viewFile = "{$pkgDir}/src/Resources/views/emails/dry-mail.blade.php";

        $this->assertFalse($this->filesystem->exists($mailFile));
        $this->assertFalse($this->filesystem->exists($viewFile));
    }

    public function test_make_mail_collision_fails_without_force_and_overwrites_with_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailCollisionPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailCollisionPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailCollisionPkg ResetPasswordMail')->assertExitCode(0);

        // Second run without force fails due to collision
        $this->artisan('laraseed:make-mail AcmeTest/MailCollisionPkg ResetPasswordMail')->assertExitCode(1);

        // Second run with force succeeds
        $this->artisan('laraseed:make-mail AcmeTest/MailCollisionPkg ResetPasswordMail --force')->assertExitCode(0);
    }

    public function test_make_mail_rejects_invalid_identifiers_and_path_traversal(): void
    {
        $this->trackDirectory('packages/AcmeTest/MailInvalidPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailInvalidPkg')->assertExitCode(0);

        $invalidNames = [
            '../SneakyMail',
            'Foo/BarMail',
            'Invalid-Name',
            '123InvalidStart',
            'Invalid Space',
            'Invalid@Symbol',
        ];

        foreach ($invalidNames as $name) {
            $this->artisan("laraseed:make-mail AcmeTest/MailInvalidPkg \"{$name}\"")
                ->assertExitCode(1);
        }

        $invalidViews = [
            '../sneaky.view',
            'emails/slash/view',
            '.leading.dot',
            'trailing.dot.',
            'invalid space.view',
        ];

        foreach ($invalidViews as $view) {
            $this->artisan("laraseed:make-mail AcmeTest/MailInvalidPkg SafeMail --view=\"{$view}\"")
                ->assertExitCode(1);
        }
    }

    public function test_make_mail_transactional_rollback_on_injected_failure(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailTxPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailTxPkg')->assertExitCode(0);

        $tx = new FilesystemTransaction(new Filesystem, base_path());
        $plan = new GenerationPlan(
            'packages/AcmeTest/MailTxPkg',
            base_path(),
            [
                'src/Mail/TxMail.php' => '<?php // mail',
                'src/Resources/views/emails/tx-mail.blade.php' => '<h1>test</h1>',
            ]
        );

        try {
            $tx->run(function (FilesystemTransaction $currentTx) use ($plan) {
                $currentTx->executePlan($plan, false);
                throw new \RuntimeException('Simulated mid-flight crash during mail generation');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Generation failed with transactional rollback: Simulated mid-flight crash during mail generation', $e->getMessage());
        }

        // Verify both Mailable and View were rolled back cleanly
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Mail/TxMail.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Resources/views/emails/tx-mail.blade.php"));
    }

    public function test_generated_mailable_renders_and_works_with_mail_fake(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MailRenderPkg');
        $this->artisan('laraseed:make-package AcmeTest/MailRenderPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-mail AcmeTest/MailRenderPkg WelcomeDigestMail --queued')
            ->assertExitCode(0);

        $mailPath = "{$pkgDir}/src/Mail/WelcomeDigestMail.php";
        $viewPath = "{$pkgDir}/src/Resources/views";

        $this->assertTrue(file_exists($mailPath));
        require_once $mailPath;

        $mailClass = 'AcmeTest\MailRenderPkg\Mail\WelcomeDigestMail';
        $this->assertTrue(class_exists($mailClass));

        // Register package views namespace in View factory for test rendering
        View::addNamespace('mail_render_pkg', $viewPath);

        /** @var Mailable $mailable */
        $mailable = new $mailClass();
        $this->assertInstanceOf(Mailable::class, $mailable);
        $this->assertInstanceOf(ShouldQueue::class, $mailable);

        // Assert envelope
        $envelope = $mailable->envelope();
        $this->assertSame('Welcome Digest Mail', $envelope->subject);

        // Assert rendered HTML contains expected template body
        $renderedHtml = $mailable->render();
        $this->assertStringContainsString('Welcome Digest Mail', $renderedHtml);
        $this->assertStringContainsString('transactional email message', $renderedHtml);

        // Test queued mailable with Mail::fake()
        Mail::fake();
        Mail::to('customer@example.com')->send($mailable);

        Mail::assertQueued($mailClass, function ($mail) {
            return $mail->hasTo('customer@example.com');
        });
    }
}
