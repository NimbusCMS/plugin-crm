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
use PHPUnit\Framework\TestCase;

/**
 * The Deals service — the pipeline, with the write discipline the review pinned:
 * a field allow-list, allow-listed stage/status enums (never interpolated), a
 * bounded non-negative money value, soft contact/org links validated to exist,
 * bound + wildcard-escaped title search, and a total delete that takes the deal's
 * activity timeline with it.
 */
final class DealsTest extends TestCase
{
    private Deals $deals;
    private Contacts $contacts;
    private Organizations $organizations;
    private Activities $activities;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...Schema::contacts(), ...Schema::organizations(), ...Schema::activities(), ...Schema::deals()] as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::CONTACT);
        $db->execute('TRUNCATE ' . Schema::ORGANIZATION);
        $db->execute('TRUNCATE ' . Schema::ACTIVITY);
        $db->execute('TRUNCATE ' . Schema::DEAL);

        $storage             = new PluginStorage($db);
        $this->deals         = new Deals(static fn (): PluginStorage => $storage);
        $this->contacts      = new Contacts(static fn (): PluginStorage => $storage);
        $this->organizations = new Organizations(static fn (): PluginStorage => $storage);
        $this->activities    = new Activities(static fn (): PluginStorage => $storage);
    }

    private const NOW = '2026-01-01 09:00:00';

    public function test_create_defaults_and_update_round_trip(): void
    {
        $id = $this->deals->save(null, ['title' => 'Engine build'], self::NOW);
        $d  = $this->deals->get($id);
        self::assertNotNull($d);
        self::assertSame('lead', $d['stage'], 'stage defaults to lead');
        self::assertSame('open', $d['status'], 'status defaults to open');
        self::assertSame('USD', $d['currency']);
        self::assertNull($d['value']);

        // Update only the stage; the title is unchanged.
        $this->deals->save($id, ['stage' => 'qualified'], '2026-01-02 09:00:00');
        $d = $this->deals->get($id);
        self::assertSame('qualified', $d['stage']);
        self::assertSame('Engine build', $d['title']);
    }

    public function test_a_deal_needs_a_title(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(null, ['value' => '100'], self::NOW);
    }

    public function test_money_is_normalised_and_bounded(): void
    {
        $id = $this->deals->save(null, ['title' => 'X', 'value' => '1,500.5', 'currency' => 'gbp'], self::NOW);
        $d  = $this->deals->get($id);
        self::assertSame('1500.50', $d['value'], 'commas stripped, two decimal places');
        self::assertSame('GBP', $d['currency'], 'currency upper-cased');
    }

    public function test_a_negative_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(null, ['title' => 'X', 'value' => '-5'], self::NOW);
    }

    public function test_a_non_numeric_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(null, ['title' => 'X', 'value' => '1000; DROP TABLE'], self::NOW);
    }

    public function test_stage_and_status_are_allow_listed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(null, ['title' => 'X', 'stage' => 'schmoozing'], self::NOW);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(null, ['title' => 'X', 'status' => 'maybe'], self::NOW);
    }

    public function test_links_must_exist_and_resolve_names(): void
    {
        $cid   = $this->contacts->save(null, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], self::NOW);
        $orgId = $this->organizations->save(null, ['name' => 'Acme'], self::NOW);

        $id = $this->deals->save(null, ['title' => 'X', 'contact_id' => (string) $cid, 'org_id' => (string) $orgId], self::NOW);
        $d  = $this->deals->get($id);
        self::assertSame('Ada Lovelace', $d['contact']);
        self::assertSame('Acme', $d['organization']);
    }

    public function test_a_missing_contact_link_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(null, ['title' => 'X', 'contact_id' => '999999'], self::NOW);
    }

    public function test_over_posting_is_ignored(): void
    {
        $id = $this->deals->save(null, ['title' => 'X', 'id' => 4242, 'created_at' => '1900-01-01 00:00:00', 'evil' => 'x'], self::NOW);
        self::assertNotSame(4242, $id);
        $d = $this->deals->get($id);
        self::assertSame(self::NOW, $d['created_at'], 'created_at is server-set');
        self::assertArrayNotHasKey('evil', $d);
    }

    public function test_search_is_bound_and_status_filters(): void
    {
        $this->deals->save(null, ['title' => 'Acme renewal', 'status' => 'open'], self::NOW);
        $this->deals->save(null, ['title' => 'Acme upsell', 'status' => 'won'], self::NOW);
        $this->deals->save(null, ['title' => 'Globex', 'status' => 'open'], self::NOW);

        self::assertCount(2, $this->deals->all('Acme'));
        self::assertCount(0, $this->deals->all('%'), 'a literal % is not match-all');
        self::assertCount(0, $this->deals->all("' OR '1'='1"));
        self::assertCount(2, $this->deals->all(null, 'open'));
        self::assertCount(1, $this->deals->all(null, 'won'));
    }

    public function test_board_is_ordered_by_pipeline_stage(): void
    {
        $this->deals->save(null, ['title' => 'A', 'stage' => 'negotiation'], self::NOW);
        $this->deals->save(null, ['title' => 'B', 'stage' => 'lead'], self::NOW);
        $this->deals->save(null, ['title' => 'C', 'stage' => 'proposal'], self::NOW);

        $stages = array_column($this->deals->all(null, 'open'), 'stage');
        self::assertSame(['lead', 'proposal', 'negotiation'], $stages, 'ordered along the pipeline');
    }

    public function test_delete_takes_the_activity_timeline_with_it(): void
    {
        $id = $this->deals->save(null, ['title' => 'X'], self::NOW);
        $this->activities->add(['subject_type' => 'deal', 'subject_id' => (string) $id, 'body' => 'note'], self::NOW);
        self::assertCount(1, $this->activities->forSubject('deal', $id));

        self::assertSame(1, $this->deals->delete($id));
        self::assertNull($this->deals->get($id));
        self::assertSame([], $this->activities->forSubject('deal', $id), 'no activity residue');
        self::assertSame(0, $this->deals->delete($id), 'a second delete is a no-op');
    }

    public function test_updating_a_missing_deal_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deals->save(424242, ['title' => 'Ghost'], self::NOW);
    }
}
