<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The Contacts service — the write discipline the security review pinned: a field
 * allow-list (no over-posting), validation (name required, email well-formed,
 * length caps), bound + wildcard-escaped search, store-raw, and a total delete.
 */
final class ContactsTest extends TestCase
{
    private Contacts $contacts;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach (Schema::contacts() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::CONTACT);

        $storage        = new PluginStorage($db);
        $this->contacts = new Contacts(static fn (): PluginStorage => $storage);
    }

    private const NOW = '2026-01-01 09:00:00';

    public function test_create_get_and_update_round_trip(): void
    {
        $id = $this->contacts->save(null, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '555-1234', 'notes' => 'First programmer.'], self::NOW);
        self::assertGreaterThan(0, $id);

        $c = $this->contacts->get($id);
        self::assertNotNull($c);
        self::assertSame('Ada', $c['first_name']);
        self::assertSame('ada@example.test', $c['email']);

        // Update only the phone; the rest keep their stored value.
        $this->contacts->save($id, ['phone' => '555-9999'], '2026-01-02 09:00:00');
        $c = $this->contacts->get($id);
        self::assertSame('555-9999', $c['phone']);
        self::assertSame('Ada', $c['first_name'], 'unsent fields are unchanged');
    }

    public function test_a_contact_needs_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->contacts->save(null, ['email' => 'noname@example.test'], self::NOW);
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->contacts->save(null, ['first_name' => 'Bad', 'email' => 'not-an-email'], self::NOW);
    }

    public function test_over_posting_is_ignored(): void
    {
        // Forged id/created_at/updated_at + an unknown column must not be assigned.
        $id = $this->contacts->save(null, [
            'first_name' => 'Grace', 'id' => 9999, 'created_at' => '1900-01-01 00:00:00',
            'is_admin' => 1, 'evil' => 'x',
        ], self::NOW);

        self::assertNotSame(9999, $id, 'the id is server-assigned, not client-set');
        $c = $this->contacts->get($id);
        self::assertNotNull($c);
        self::assertSame(self::NOW, $c['created_at'], 'created_at is server-set, not over-posted');
        self::assertArrayNotHasKey('is_admin', $c);
    }

    public function test_search_is_bound_and_wildcard_escaped(): void
    {
        $this->contacts->save(null, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'], self::NOW);
        $this->contacts->save(null, ['first_name' => 'Alan', 'last_name' => 'Turing', 'email' => 'alan@example.test'], self::NOW);

        self::assertCount(1, $this->contacts->all('Lovelace'));
        self::assertCount(2, $this->contacts->all('example.test'));
        // A literal % must not act as match-all (wildcards are escaped).
        self::assertCount(0, $this->contacts->all('%'));
        // A quote must not break the query (bound param).
        self::assertCount(0, $this->contacts->all("' OR '1'='1"));
    }

    public function test_delete_is_total(): void
    {
        $id = $this->contacts->save(null, ['first_name' => 'Forget', 'last_name' => 'Me', 'email' => 'forget@example.test'], self::NOW);
        self::assertSame(1, $this->contacts->delete($id));
        self::assertNull($this->contacts->get($id), 'no residue after a delete');
        self::assertSame(0, $this->contacts->delete($id), 'a second delete is a no-op');
    }

    public function test_updating_a_missing_contact_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->contacts->save(424242, ['first_name' => 'Ghost'], self::NOW);
    }
}
