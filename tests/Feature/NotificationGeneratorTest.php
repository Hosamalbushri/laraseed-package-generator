<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Laraseed\PackageGenerator\Generators\FilesystemTransaction;
use Laraseed\PackageGenerator\Generators\GenerationPlan;
use Laraseed\PackageGenerator\Generators\NotificationGenerator;
use Laraseed\PackageGenerator\Tests\TestCase;

class NotificationGeneratorTest extends TestCase
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

    public function test_make_notification_generates_standard_mail_notification(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyPkg OrderCreatedNotification')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/OrderCreatedNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString('namespace AcmeTest\NotifyPkg\Notifications;', $content);
        $this->assertStringContainsString('class OrderCreatedNotification extends Notification', $content);
        $this->assertStringNotContainsString('implements ShouldQueue', $content);
        $this->assertStringContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringContainsString("return ['mail'];", $content);
        $this->assertStringContainsString('public function toMail(object $notifiable): MailMessage', $content);
        $this->assertStringContainsString("subject('Order Created Notification')", $content);
        $this->assertStringNotContainsString('public function toArray', $content);
    }

    public function test_make_notification_with_database_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyDbPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyDbPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyDbPkg LeadAssignedNotification --database')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/LeadAssignedNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['mail', 'database'];", $content);
        $this->assertStringContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringContainsString('public function toMail(object $notifiable): MailMessage', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
        $this->assertStringContainsString("'title'      => 'Lead Assigned Notification'", $content);
    }

    public function test_make_notification_with_broadcast_flag_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyBcFlagPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyBcFlagPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyBcFlagPkg ActivityAlertNotification --broadcast')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/ActivityAlertNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['mail', 'broadcast'];", $content);
        $this->assertStringContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringContainsString('public function toMail(object $notifiable): MailMessage', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_with_channels_broadcast_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyBcChannelsPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyBcChannelsPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyBcChannelsPkg RealtimePingNotification --channels=broadcast')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/RealtimePingNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['broadcast'];", $content);
        $this->assertStringNotContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringNotContainsString('public function toMail', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_with_channels_mail_broadcast_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyMailBcPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyMailBcPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyMailBcPkg OrderShippedNotification --channels=mail,broadcast')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/OrderShippedNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['mail', 'broadcast'];", $content);
        $this->assertStringContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringContainsString('public function toMail(object $notifiable): MailMessage', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_with_channels_mail_database_broadcast_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyAllPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyAllPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyAllPkg InvoicePaidNotification --channels=mail,database,broadcast')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/InvoicePaidNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['mail', 'database', 'broadcast'];", $content);
        $this->assertStringContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringContainsString('public function toMail(object $notifiable): MailMessage', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_with_combined_convenience_flags(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyCombinedFlagsPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyCombinedFlagsPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyCombinedFlagsPkg CriticalAlertNotification --database --broadcast')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/CriticalAlertNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['mail', 'database', 'broadcast'];", $content);
        $this->assertStringContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringContainsString('public function toMail(object $notifiable): MailMessage', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_database_only_import_hygiene(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyDbOnlyHygienePkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyDbOnlyHygienePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyDbOnlyHygienePkg LogEventNotification --channels=database')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/LogEventNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['database'];", $content);
        $this->assertStringNotContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringNotContainsString('public function toMail', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_broadcast_only_import_hygiene(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyBcOnlyHygienePkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyBcOnlyHygienePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyBcOnlyHygienePkg SocketPushNotification --channels=broadcast')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/SocketPushNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString("return ['broadcast'];", $content);
        $this->assertStringNotContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
        $this->assertStringNotContainsString('public function toMail', $content);
        $this->assertStringContainsString('public function toArray(object $notifiable): array', $content);
    }

    public function test_make_notification_with_queued_broadcast_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyQueuedBcPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyQueuedBcPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyQueuedBcPkg AsyncBroadcastNotification --channels=broadcast --queued')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/AsyncBroadcastNotification.php";
        $this->assertTrue($this->filesystem->exists($notifyFile));

        $content = (string) file_get_contents($notifyFile);
        $this->assertStringContainsString('class AsyncBroadcastNotification extends Notification implements ShouldQueue', $content);
        $this->assertStringNotContainsString('use Illuminate\Notifications\Messages\MailMessage;', $content);
    }

    public function test_make_notification_dry_run_mode_creates_no_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyDryRunPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyDryRunPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyDryRunPkg DryNotification --dry-run')
            ->assertExitCode(0);

        $notifyFile = "{$pkgDir}/src/Notifications/DryNotification.php";
        $this->assertFalse($this->filesystem->exists($notifyFile));
    }

    public function test_make_notification_collision_fails_without_force_and_overwrites_with_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyCollisionPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyCollisionPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-notification AcmeTest/NotifyCollisionPkg DealWonNotification')
            ->assertExitCode(0);

        // Second run without force must fail due to collision
        $this->artisan('laraseed:make-notification AcmeTest/NotifyCollisionPkg DealWonNotification')
            ->assertExitCode(1);

        // Second run with force must succeed
        $this->artisan('laraseed:make-notification AcmeTest/NotifyCollisionPkg DealWonNotification --force')
            ->assertExitCode(0);
    }

    public function test_make_notification_rejects_invalid_identifiers_and_path_traversal(): void
    {
        $this->trackDirectory('packages/AcmeTest/NotifyInvalidPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyInvalidPkg')->assertExitCode(0);

        $invalidNames = [
            '../SneakyNotification',
            'Foo/BarNotification',
            'Invalid-Name',
            '123InvalidStart',
            'Invalid Space',
            'Invalid@Symbol',
        ];

        foreach ($invalidNames as $name) {
            $this->artisan("laraseed:make-notification AcmeTest/NotifyInvalidPkg \"{$name}\"")
                ->assertExitCode(1);
        }

        // Test invalid channel rejection
        $this->artisan('laraseed:make-notification AcmeTest/NotifyInvalidPkg ValidNotification --channels=unsupported_channel')
            ->assertExitCode(1);
    }

    public function test_make_notification_transactional_rollback_on_injected_failure(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyTxPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyTxPkg')->assertExitCode(0);

        $tx = new FilesystemTransaction(new Filesystem, base_path());
        $plan = new GenerationPlan(
            'packages/AcmeTest/NotifyTxPkg',
            base_path(),
            ['src/Notifications/TxNotification.php' => '<?php // test']
        );

        try {
            $tx->run(function (FilesystemTransaction $currentTx) use ($plan) {
                $currentTx->executePlan($plan, false);
                throw new \RuntimeException('Simulated mid-flight crash during notification generation');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Generation failed with transactional rollback: Simulated mid-flight crash during notification generation', $e->getMessage());
        }

        // Verify transaction rolled back created file cleanly
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Notifications/TxNotification.php"));
    }

    public function test_generated_notification_real_framework_channel_dispatch(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NotifyExecRealPkg');
        $this->artisan('laraseed:make-package AcmeTest/NotifyExecRealPkg')->assertExitCode(0);

        // Generate 3-channel queued notification
        $this->artisan('laraseed:make-notification AcmeTest/NotifyExecRealPkg TicketResolvedNotification --channels=mail,database,broadcast --queued')
            ->assertExitCode(0);

        $notifyPath = "{$pkgDir}/src/Notifications/TicketResolvedNotification.php";
        $this->assertTrue(file_exists($notifyPath));

        require_once $notifyPath;

        $notifyClass = 'AcmeTest\NotifyExecRealPkg\Notifications\TicketResolvedNotification';
        $this->assertTrue(class_exists($notifyClass));

        /** @var Notification $notification */
        $notification = new $notifyClass();
        $this->assertInstanceOf(Notification::class, $notification);
        $this->assertInstanceOf(ShouldQueue::class, $notification);

        // Ensure sqlite notifications and users tables exist
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('test_notifiable_users')) {
            Schema::create('test_notifiable_users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->default('Test User');
                $table->string('email')->default('recipient@example.com');
                $table->timestamps();
            });
        }

        \Illuminate\Support\Facades\DB::table('test_notifiable_users')->insertOrIgnore([
            'id' => 999,
            'name' => 'Test User',
            'email' => 'recipient@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testUser = TestNotifiableUser::find(999);

        // Configure framework drivers for test execution
        config(['mail.default' => 'array']);
        config(['broadcasting.default' => 'log']);
        config(['broadcasting.connections.log' => ['driver' => 'log']]);
        config(['queue.default' => 'sync']);

        // Listen for real BroadcastNotificationCreated event
        $capturedBroadcastEvents = [];
        Event::listen(BroadcastNotificationCreated::class, function ($event) use (&$capturedBroadcastEvents) {
            $capturedBroadcastEvents[] = $event;
        });

        // Execute real dispatch via Notification facade
        NotificationFacade::send($testUser, $notification);

        // 1. Verify Mail Channel Execution
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'Mail channel failed to render and deliver message.');
        $sentMessage = $messages instanceof \Illuminate\Support\Collection ? $messages->last() : end($messages);
        $email = method_exists($sentMessage, 'getOriginalMessage') ? $sentMessage->getOriginalMessage() : $sentMessage;
        $this->assertSame('Ticket Resolved Notification', $email->getSubject());

        // 2. Verify Database Channel Execution
        $persisted = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_id', 999)
            ->where('type', $notifyClass)
            ->first();
        $this->assertNotNull($persisted, 'Database notification was not persisted to database.');
        $data = json_decode($persisted->data, true);
        $this->assertSame('Ticket Resolved Notification', $data['title']);
        $this->assertStringContainsString('Ticket Resolved Notification', $data['message']);

        // 3. Verify Broadcast Channel Execution
        $this->assertCount(1, $capturedBroadcastEvents, 'Broadcast channel failed to dispatch BroadcastNotificationCreated event.');
        $bcEvent = $capturedBroadcastEvents[0];
        $this->assertInstanceOf(\Illuminate\Contracts\Broadcasting\ShouldBroadcast::class, $bcEvent);
        $this->assertSame($notifyClass, $bcEvent->broadcastType());
        $this->assertSame('Illuminate\Notifications\Events\BroadcastNotificationCreated', $bcEvent->broadcastAs());

        $broadcastPayload = $bcEvent->broadcastWith();
        $this->assertSame('Ticket Resolved Notification', $broadcastPayload['title']);
        $this->assertSame($notifyClass, $broadcastPayload['type']);
        $this->assertNotNull($broadcastPayload['id']);
    }
}

class TestNotifiableUser extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Notifications\Notifiable;

    protected $table = 'test_notifiable_users';

    protected $guarded = [];

    public function routeNotificationForMail($notification = null): string
    {
        return $this->email;
    }
}
