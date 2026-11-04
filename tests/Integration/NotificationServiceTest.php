<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\NotificationService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Time-based reminders sent by cron/daily.php — each must be sent exactly once. */
final class NotificationServiceTest extends DatabaseTestCase
{
    private array $admin;
    private array $organizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin     = Fixtures::user('administrator');
        $this->organizer = Fixtures::user('organizer');
    }

    /** Rozjednaná akce za 5 dní: jedna připomínka (7denní) organizátorům i adminům, neopakuje se. */
    public function testDraftCloseToItsStartGetsOneReminder(): void
    {
        Fixtures::action(['name' => 'Rozjednaná', 'public_start_at' => Fixtures::at('+5 days 17:00'), 'organizers' => [$this->organizer['id']]]);

        (new NotificationService())->sendDraftStartReminders();
        (new NotificationService())->sendDraftStartReminders();

        $texts = $this->notificationsOf($this->organizer['id']);
        $this->assertCount(1, $texts, 'within 7 days: only the 7-day reminder, and only once');
        $this->assertStringContainsString('(za 5 dní)', $texts[0]);
        $this->assertCount(1, $this->notificationsOf($this->admin['id']), 'administrators get it too');
    }

    /** Rozjednaná akce za 20 dní dostane 30denní připomínku. */
    public function testDraftAMonthAheadGetsThe30DayReminder(): void
    {
        $action = Fixtures::action(['public_start_at' => Fixtures::at('+20 days 17:00'), 'organizers' => [$this->organizer['id']]]);

        (new NotificationService())->sendDraftStartReminders();

        $this->assertCount(1, $this->notificationsOf($this->organizer['id']));
        $this->assertSame(30, (int) $this->scalar('SELECT days_before FROM action_start_reminders WHERE action_id = ?', [$action]));
    }

    /** Potvrzená nebo vzdálená akce připomínku „stále Rozjednaná“ nedostane. */
    public function testConfirmedOrDistantActionsGetNoDraftReminder(): void
    {
        Fixtures::action(['status' => 'confirmed', 'public_start_at' => Fixtures::at('+5 days 17:00'), 'organizers' => [$this->organizer['id']]]);
        Fixtures::action(['public_start_at' => Fixtures::at('+60 days 17:00'), 'organizers' => [$this->organizer['id']]]);

        (new NotificationService())->sendDraftStartReminders();

        $this->assertSame([], $this->notificationsOf($this->organizer['id']));
    }

    /** Akce po termínu, která je stále Potvrzená, se nahlásí jednou (je třeba změnit stav). */
    public function testEndedButStillConfirmedActionIsReportedOnce(): void
    {
        Fixtures::action([
            'name'            => 'Proběhla?',
            'status'          => 'confirmed',
            'public_start_at' => Fixtures::at('-2 days 17:00'),
            'organizers'      => [$this->organizer['id']],
        ]);

        (new NotificationService())->sendOverdueActionReminders();
        (new NotificationService())->sendOverdueActionReminders();

        $texts = $this->notificationsOf($this->organizer['id']);
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('je stále ve stavu Potvrzená', $texts[0]);
    }

    /** Blížící se akce bez zabrané místnosti se nahlásí jednou; akce s místností ne. */
    public function testSoonStartingActionWithoutRoomIsReported(): void
    {
        $withoutRoom = Fixtures::action(['name' => 'Bez místnosti', 'public_start_at' => Fixtures::at('+3 days 10:00'), 'organizers' => [$this->organizer['id']]]);
        $withRoom    = Fixtures::action(['name' => 'S místností', 'public_start_at' => Fixtures::at('+3 days 15:00'), 'organizers' => [$this->organizer['id']]]);
        Fixtures::confirm($withRoom, Fixtures::place('Sál'));

        (new NotificationService())->sendMissingRoomReminders();
        (new NotificationService())->sendMissingRoomReminders();

        $texts = $this->notificationsOf($this->organizer['id']);
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('"Bez místnosti"', $texts[0]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM action_alerts WHERE action_id = ? AND alert_code = 'no_room'", [$withoutRoom]));
    }

    /**
     * Připomínku dostanou jen aktuální organizátoři a zástupci akce a aktuální administrátoři —
     * ne bývalý administrátor (role odebrána), ne odebraný organizátor/zástupce, ne nezúčastněný organizátor (regrese 4. 11. 2026).
     */
    public function testRemindersGoOnlyToCurrentOrganizersSubstitutesAndAdmins(): void
    {
        $substitute        = Fixtures::user('substitute');
        $formerAdmin       = Fixtures::user(['administrator', 'organizer']);
        $removedOrganizer  = Fixtures::user('organizer');
        $removedSubstitute = Fixtures::user('substitute');
        $uninvolved        = Fixtures::user('organizer');
        $action = Fixtures::action([
            'name'            => 'Bez místnosti',
            'public_start_at' => Fixtures::at('+3 days 10:00'),
            'organizers'      => [$this->organizer['id'], $removedOrganizer['id']],
            'substitutes'     => [$substitute['id'], $removedSubstitute['id']],
        ]);
        $this->pdo->prepare('UPDATE user_roles SET deactivated_at = NOW() WHERE user_id = ? AND role_id = ?')
            ->execute([$formerAdmin['id'], Fixtures::roleId('administrator')]);
        $this->pdo->prepare('UPDATE action_organizers SET deactivated_at = NOW() WHERE action_id = ? AND user_id = ?')
            ->execute([$action, $removedOrganizer['id']]);
        $this->pdo->prepare('UPDATE action_substitutes SET deactivated_at = NOW() WHERE action_id = ? AND user_id = ?')
            ->execute([$action, $removedSubstitute['id']]);

        (new NotificationService())->sendMissingRoomReminders();

        $this->assertCount(1, $this->notificationsOf($this->organizer['id']), 'organizer');
        $this->assertCount(1, $this->notificationsOf($substitute['id']), 'substitute');
        $this->assertCount(1, $this->notificationsOf($this->admin['id']), 'current administrator');
        $this->assertSame([], $this->notificationsOf($formerAdmin['id']), 'former administrator');
        $this->assertSame([], $this->notificationsOf($removedOrganizer['id']), 'removed organizer');
        $this->assertSame([], $this->notificationsOf($removedSubstitute['id']), 'removed substitute');
        $this->assertSame([], $this->notificationsOf($uninvolved['id']), 'organizer of other actions');
    }

    /** Kdo je zároveň zástupcem akce i administrátorem, dostane připomínku jen jednou. */
    public function testRecipientWithSeveralRolesGetsTheReminderOnce(): void
    {
        Fixtures::action([
            'public_start_at' => Fixtures::at('+3 days 10:00'),
            'organizers'      => [$this->organizer['id']],
            'substitutes'     => [$this->admin['id']],
        ]);

        (new NotificationService())->sendMissingRoomReminders();

        $this->assertCount(1, $this->notificationsOf($this->admin['id']));
    }

    /** Upozornění se odešle emailem na přihlašovací adresu uživatele a odeslání se zaznamená. */
    public function testNotificationIsEmailedToTheLoginAddress(): void
    {
        if (!str_contains((string) ini_get('sendmail_path'), 'fake-sendmail')) {
            $this->markTestSkipped('Run through tests/run.sh — it routes mail() to tests/bin/fake-sendmail.');
        }
        array_map('unlink', glob(__DIR__ . '/../tmp/mail/*.eml') ?: []);

        (new NotificationService())->send($this->organizer['id'], 'Testovací upozornění');

        $this->assertNotNull($this->scalar('SELECT email_sent_at FROM notifications'));
        $mails = glob(__DIR__ . '/../tmp/mail/*.eml') ?: [];
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('To: ' . $this->organizer['email'], (string) file_get_contents($mails[0]));
    }
}
