<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Activities;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\Organizations;
use NimbusCMS\Crm\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The Activities service — the timeline, plus the polymorphic-subject discipline the
 * security review pinned: subject_type is a write-time allow-list (never
 * interpolated), the subject must exist, kind is an allow-list, author is server-set
 * (not over-postable), and occurred_at is parsed strictly.
 */
final class ActivitiesTest extends TestCase
{
    private Activities $activities;
    private Contacts $contacts;
    private Organizations $organizations;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...Schema::contacts(), ...Schema::organizations(), ...Schema::activities()] as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::CONTACT);
        $db->execute('TRUNCATE ' . Schema::ORGANIZATION);
        $db->execute('TRUNCATE ' . Schema::ACTIVITY);

        $storage             = new PluginStorage($db);
        $this->activities    = new Activities(static fn (): PluginStorage => $storage);
        $this->contacts      = new Contacts(static fn (): PluginStorage => $storage);
        $this->organizations = new Organizations(static fn (): PluginStorage => $storage);
    }

    private const NOW = '2026-01-01 09:00:00';

    private function contactId(): int
    {
        return $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
    }

    public function test_add_get_and_timeline_ordering(): void
    {
        $cid = $this->contactId();
        $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'kind' => 'call', 'body' => 'First', 'occurred_at' => '2026-01-01 08:00:00'], self::NOW);
        $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'kind' => 'note', 'body' => 'Second', 'occurred_at' => '2026-01-02 08:00:00'], self::NOW);

        $timeline = $this->activities->forSubject('contact', $cid);
        self::assertCount(2, $timeline);
        self::assertSame('Second', $timeline[0]['body'], 'most recent occurred_at first');
        self::assertSame('First', $timeline[1]['body']);
        self::assertSame('call', $timeline[1]['kind']);
    }

    public function test_kind_defaults_to_note(): void
    {
        $cid = $this->contactId();
        $id  = $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'body' => 'x'], self::NOW);
        self::assertSame('note', $this->activities->get($id)['kind']);
    }

    public function test_occurred_at_defaults_to_now_and_accepts_datetime_local(): void
    {
        $cid = $this->contactId();
        $a   = $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'body' => 'x'], self::NOW);
        self::assertSame(self::NOW, $this->activities->get($a)['occurred_at']);

        // A datetime-local value ("T" separator, no seconds) is normalized.
        $b = $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'occurred_at' => '2026-03-04T14:30'], self::NOW);
        self::assertSame('2026-03-04 14:30:00', $this->activities->get($b)['occurred_at']);
    }

    public function test_a_bad_occurred_at_is_rejected(): void
    {
        $cid = $this->contactId();
        $this->expectException(\InvalidArgumentException::class);
        $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'occurred_at' => 'yesterday'], self::NOW);
    }

    public function test_an_unknown_kind_is_rejected(): void
    {
        $cid = $this->contactId();
        $this->expectException(\InvalidArgumentException::class);
        $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'kind' => 'carrier-pigeon'], self::NOW);
    }

    public function test_subject_type_is_an_allow_list(): void
    {
        // "deal" is reserved in the column ENUM but not yet a valid write subject.
        $this->expectException(\InvalidArgumentException::class);
        $this->activities->add(['subject_type' => 'deal', 'subject_id' => '1', 'body' => 'x'], self::NOW);
    }

    public function test_an_arbitrary_subject_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->activities->add(['subject_type' => 'user', 'subject_id' => '1', 'body' => 'x'], self::NOW);
    }

    public function test_the_subject_must_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->activities->add(['subject_type' => 'contact', 'subject_id' => '999999', 'body' => 'ghost'], self::NOW);
    }

    public function test_an_activity_can_hang_off_an_organization(): void
    {
        $orgId = $this->organizations->save(null, ['name' => 'Acme'], self::NOW);
        $id    = $this->activities->add(['subject_type' => 'organization', 'subject_id' => (string) $orgId, 'body' => 'Renewed.'], self::NOW);
        self::assertSame($orgId, $this->activities->get($id)['subject_id']);
        self::assertCount(1, $this->activities->forSubject('organization', $orgId));
    }

    public function test_author_is_server_set_never_over_posted(): void
    {
        $cid = $this->contactId();
        // A forged author key in the field array is ignored; only the explicit arg lands.
        $id = $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'author' => 'spoof', 'body' => 'x'], self::NOW, 'crm-bot');
        self::assertSame('crm-bot', $this->activities->get($id)['author']);
    }

    public function test_delete_removes_one_entry(): void
    {
        $cid = $this->contactId();
        $id  = $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cid, 'body' => 'x'], self::NOW);
        self::assertSame(1, $this->activities->delete($id));
        self::assertNull($this->activities->get($id));
        self::assertSame(0, $this->activities->delete($id), 'a second delete is a no-op');
    }

    public function test_an_unknown_subject_type_yields_an_empty_timeline_not_an_error(): void
    {
        self::assertSame([], $this->activities->forSubject('deal', 1));
    }
}
