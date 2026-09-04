<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Activities;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\Deals;
use NimbusCMS\Crm\Organizations;
use NimbusCMS\Crm\Schema;
use NimbusCMS\Crm\Tags;
use PHPUnit\Framework\TestCase;

/**
 * The Organizations service — the same write discipline as Contacts (allow-list,
 * name required, length caps, bound + wildcard-escaped search, store-raw), plus the
 * one behaviour unique to orgs: a delete unlinks its contacts atomically instead of
 * destroying them.
 */
final class OrganizationsTest extends TestCase
{
    private Organizations $organizations;
    private Contacts $contacts;
    private Activities $activities;
    private Deals $deals;
    private Tags $tags;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...Schema::contacts(), ...Schema::organizations(), ...Schema::activities(), ...Schema::deals(), ...Schema::tags()] as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::CONTACT);
        $db->execute('TRUNCATE ' . Schema::ORGANIZATION);
        $db->execute('TRUNCATE ' . Schema::ACTIVITY);
        $db->execute('TRUNCATE ' . Schema::DEAL);
        $db->execute('TRUNCATE ' . Schema::TAG);
        $db->execute('TRUNCATE ' . Schema::TAGGABLE);

        $storage             = new PluginStorage($db);
        $this->organizations = new Organizations(static fn (): PluginStorage => $storage);
        $this->contacts      = new Contacts(static fn (): PluginStorage => $storage);
        $this->activities    = new Activities(static fn (): PluginStorage => $storage);
        $this->deals         = new Deals(static fn (): PluginStorage => $storage);
        $this->tags          = new Tags(static fn (): PluginStorage => $storage);
    }

    private const NOW = '2026-01-01 09:00:00';

    public function test_create_get_and_update_round_trip(): void
    {
        $id = $this->organizations->save(null, ['name' => 'Acme', 'website' => 'https://acme.test', 'notes' => 'A customer.'], self::NOW);
        self::assertGreaterThan(0, $id);

        $o = $this->organizations->get($id);
        self::assertNotNull($o);
        self::assertSame('Acme', $o['name']);
        self::assertSame('https://acme.test', $o['website']);

        // Update only the website; the rest keep their stored value.
        $this->organizations->save($id, ['website' => 'https://acme.example'], '2026-01-02 09:00:00');
        $o = $this->organizations->get($id);
        self::assertSame('https://acme.example', $o['website']);
        self::assertSame('Acme', $o['name'], 'unsent fields are unchanged');
    }

    public function test_a_name_is_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->organizations->save(null, ['website' => 'https://noname.test'], self::NOW);
    }

    public function test_over_posting_is_ignored(): void
    {
        $id = $this->organizations->save(null, [
            'name' => 'Grace Inc', 'id' => 9999, 'created_at' => '1900-01-01 00:00:00', 'evil' => 'x',
        ], self::NOW);

        self::assertNotSame(9999, $id, 'the id is server-assigned');
        $o = $this->organizations->get($id);
        self::assertNotNull($o);
        self::assertSame(self::NOW, $o['created_at'], 'created_at is server-set, not over-posted');
        self::assertArrayNotHasKey('evil', $o);
    }

    public function test_search_is_bound_and_wildcard_escaped(): void
    {
        $this->organizations->save(null, ['name' => 'Acme'], self::NOW);
        $this->organizations->save(null, ['name' => 'Acme Analytics'], self::NOW);
        $this->organizations->save(null, ['name' => 'Globex'], self::NOW);

        self::assertCount(2, $this->organizations->all('Acme'));
        self::assertCount(0, $this->organizations->all('%'), 'a literal % is not match-all');
        self::assertCount(0, $this->organizations->all("' OR '1'='1"), 'a quote does not break the query');
    }

    public function test_all_is_ordered_by_name(): void
    {
        $this->organizations->save(null, ['name' => 'Zeta'], self::NOW);
        $this->organizations->save(null, ['name' => 'Alpha'], self::NOW);

        $names = array_column($this->organizations->all(), 'name');
        self::assertSame(['Alpha', 'Zeta'], $names);
    }

    public function test_delete_unlinks_contacts_atomically_and_keeps_them(): void
    {
        $orgId = $this->organizations->save(null, ['name' => 'Doomed Co'], self::NOW);
        $a = $this->contacts->save(null, ['first_name' => 'Ada', 'org_id' => (string) $orgId], self::NOW);
        $b = $this->contacts->save(null, ['first_name' => 'Alan', 'org_id' => (string) $orgId], self::NOW);

        self::assertTrue($this->organizations->delete($orgId));
        self::assertNull($this->organizations->get($orgId));
        self::assertNull($this->contacts->get($a)['org_id'], 'every linked contact is unlinked');
        self::assertNull($this->contacts->get($b)['org_id']);
        self::assertNotNull($this->contacts->get($a), 'contacts are kept, not cascaded');

        self::assertFalse($this->organizations->delete($orgId), 'a second delete is a no-op');
    }

    public function test_delete_forgets_the_orgs_own_activities_but_not_its_peoples(): void
    {
        $orgId = $this->organizations->save(null, ['name' => 'Doomed Co'], self::NOW);
        $cId   = $this->contacts->save(null, ['first_name' => 'Ada', 'org_id' => (string) $orgId], self::NOW);

        $this->activities->add(['subject_type' => 'organization', 'subject_id' => (string) $orgId, 'body' => 'Signed the contract.'], self::NOW);
        $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $cId, 'body' => 'Personal note about Ada.'], self::NOW);

        self::assertTrue($this->organizations->delete($orgId));

        self::assertSame([], $this->activities->forSubject('organization', $orgId), "the company's own timeline is removed with it");
        self::assertCount(1, $this->activities->forSubject('contact', $cId), "the surviving person's activities are kept");
    }

    public function test_delete_keeps_its_deals_but_clears_the_link(): void
    {
        $orgId  = $this->organizations->save(null, ['name' => 'Doomed Co'], self::NOW);
        $dealId = $this->deals->save(null, ['title' => 'Big renewal', 'org_id' => (string) $orgId], self::NOW);

        self::assertTrue($this->organizations->delete($orgId));

        $deal = $this->deals->get($dealId);
        self::assertNotNull($deal, 'the deal outlives the company');
        self::assertNull($deal['org_id'], 'the dangling org link is cleared');
    }

    public function test_delete_clears_its_tag_links(): void
    {
        $orgId = $this->organizations->save(null, ['name' => 'Acme'], self::NOW);
        $tagId = $this->tags->findOrCreate('Partner', self::NOW);
        $this->tags->attach('organization', $orgId, $tagId, self::NOW);

        self::assertTrue($this->organizations->delete($orgId));
        self::assertSame([], $this->tags->idsFor('organization', $tagId), 'no tag residue for the deleted org');
    }

    public function test_exists_reports_presence(): void
    {
        $id = $this->organizations->save(null, ['name' => 'Acme'], self::NOW);
        self::assertTrue($this->organizations->exists($id));
        self::assertFalse($this->organizations->exists(999999));
    }

    public function test_updating_a_missing_organization_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->organizations->save(424242, ['name' => 'Ghost'], self::NOW);
    }
}
