<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Auth\Authorizer;
use Nimbus\Database\Connection;
use Nimbus\Mcp\McpError;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Activities;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\CrmToolset;
use NimbusCMS\Crm\Deals;
use NimbusCMS\Crm\Organizations;
use NimbusCMS\Crm\Schema;
use NimbusCMS\Crm\Tags;
use PHPUnit\Framework\TestCase;

/**
 * The MCP surface + the authorization matrix. PII is the headline risk, so the
 * load-bearing property is proven here end to end: every CRM tool is gated on the
 * wildcard-immune `nimbuscms.crm` capability — a content `*:write` token can
 * neither see nor call a CRM tool — reads need `:read`, writes need `:write`, and
 * a denied tool reports as *unknown* (non-enumerating).
 */
final class CrmToolsetTest extends TestCase
{
    private CrmToolset $toolset;
    private EntryOpContext $ctx;

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

        $storage       = new PluginStorage($db);
        $this->toolset = new CrmToolset(
            new Contacts(static fn (): PluginStorage => $storage),
            new Organizations(static fn (): PluginStorage => $storage),
            new Activities(static fn (): PluginStorage => $storage),
            new Deals(static fn (): PluginStorage => $storage),
            new Tags(static fn (): PluginStorage => $storage),
        );
        $this->toolset->bindTo('nimbuscms.crm'); // the registrar does this in prod
        $this->ctx = new EntryOpContext('127.0.0.1', '/api/v1/mcp');

        // Model the booted state: the plugin's capability is sealed as management,
        // which is what makes it wildcard-immune.
        Authorizer::useManagement(['nimbuscms.crm']);
    }

    protected function tearDown(): void
    {
        Authorizer::reset();
    }

    private function principal(string ...$scopes): TokenPrincipal
    {
        return new TokenPrincipal(1, 'crm-bot', array_values($scopes));
    }

    public function test_the_tools_are_namespaced_and_split_read_from_write(): void
    {
        $names = array_column($this->toolset->definitions($this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write')), 'name');
        self::assertSame([
            'crm_contacts', 'crm_contact_get', 'crm_contact_set', 'crm_contact_delete',
            'crm_organizations', 'crm_organization_get', 'crm_organization_set', 'crm_organization_delete',
            'crm_activities', 'crm_activity_add', 'crm_activity_delete',
            'crm_deals', 'crm_deal_get', 'crm_deal_set', 'crm_deal_delete',
            'crm_tags', 'crm_tag_create', 'crm_tag_delete', 'crm_tag_attach', 'crm_tag_detach', 'crm_tags_for', 'crm_tagged',
        ], $names);
    }

    public function test_a_read_only_token_sees_only_the_read_tools(): void
    {
        $names = array_column($this->toolset->definitions($this->principal('nimbuscms.crm:read')), 'name');
        self::assertSame([
            'crm_contacts', 'crm_contact_get', 'crm_organizations', 'crm_organization_get',
            'crm_activities', 'crm_deals', 'crm_deal_get', 'crm_tags', 'crm_tags_for', 'crm_tagged',
        ], $names);
    }

    public function test_a_content_token_cannot_reach_contacts(): void
    {
        // The PII deny-by-default, end to end: a content "all collections" token is
        // not the CRM capability, so every CRM tool is invisible and un-callable.
        self::assertSame([], $this->toolset->definitions($this->principal('*:write', '*:read')));

        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "crm_contacts"');
        $this->toolset->call('crm_contacts', [], $this->principal('*:read', '*:write'), $this->ctx);
    }

    public function test_a_token_with_no_scopes_sees_nothing(): void
    {
        self::assertSame([], $this->toolset->definitions($this->principal()));
    }

    public function test_a_read_token_cannot_call_a_write_tool(): void
    {
        $this->expectException(McpError::class);
        $this->toolset->call('crm_contact_set', ['first_name' => 'X'], $this->principal('nimbuscms.crm:read'), $this->ctx);
    }

    public function test_set_then_get_and_delete_round_trip(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');

        $out = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'], $write, $this->ctx);
        self::assertTrue($out['ok']);
        $id = $out['contact']['id'];

        $got = $this->toolset->call('crm_contact_get', ['id' => $id], $write, $this->ctx);
        self::assertSame('Ada', $got['contact']['first_name']);

        $del = $this->toolset->call('crm_contact_delete', ['id' => $id], $write, $this->ctx);
        self::assertTrue($del['deleted']);
        $gone = $this->toolset->call('crm_contact_get', ['id' => $id], $write, $this->ctx);
        self::assertNull($gone['contact'], 'a deleted contact is a clean not-found, not an oracle');
    }

    public function test_an_invalid_email_comes_back_as_data_not_an_exception(): void
    {
        $out = $this->toolset->call('crm_contact_set', ['first_name' => 'Bad', 'email' => 'nope'], $this->principal('nimbuscms.crm:write'), $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('invalid', $out['error']);
    }

    public function test_a_content_token_cannot_reach_organizations_either(): void
    {
        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "crm_organizations"');
        $this->toolset->call('crm_organizations', [], $this->principal('*:read', '*:write'), $this->ctx);
    }

    public function test_a_read_token_cannot_call_an_org_write_tool(): void
    {
        $this->expectException(McpError::class);
        $this->toolset->call('crm_organization_set', ['name' => 'X'], $this->principal('nimbuscms.crm:read'), $this->ctx);
    }

    public function test_organization_set_get_and_delete_round_trip(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');

        $out = $this->toolset->call('crm_organization_set', ['name' => 'Acme', 'website' => 'https://acme.test'], $write, $this->ctx);
        self::assertTrue($out['ok']);
        $orgId = $out['organization']['id'];

        // Link a contact to it, then delete the org — the contact must survive, unlinked.
        $c = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada', 'org_id' => $orgId], $write, $this->ctx);
        self::assertSame($orgId, $c['contact']['org_id']);
        self::assertSame('Acme', $c['contact']['organization']);

        $del = $this->toolset->call('crm_organization_delete', ['id' => $orgId], $write, $this->ctx);
        self::assertTrue($del['deleted']);

        $still = $this->toolset->call('crm_contact_get', ['id' => $c['contact']['id']], $write, $this->ctx);
        self::assertNotNull($still['contact'], 'the contact outlives its deleted org');
        self::assertNull($still['contact']['org_id'], 'the link is cleared, not cascaded');
    }

    public function test_creating_an_org_without_a_name_comes_back_as_data(): void
    {
        $out = $this->toolset->call('crm_organization_set', [], $this->principal('nimbuscms.crm:write'), $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('invalid', $out['error']);
    }

    public function test_a_content_token_cannot_reach_activities_either(): void
    {
        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "crm_activity_add"');
        $this->toolset->call('crm_activity_add', [], $this->principal('*:read', '*:write'), $this->ctx);
    }

    public function test_an_activity_is_logged_under_the_token_name_and_listed(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');
        $c     = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada'], $write, $this->ctx);
        $cid   = $c['contact']['id'];

        $out = $this->toolset->call('crm_activity_add', [
            'subject_type' => 'contact', 'subject_id' => $cid, 'kind' => 'call', 'body' => 'Discussed the engine.',
        ], $write, $this->ctx);
        self::assertTrue($out['ok']);
        self::assertSame('crm-bot', $out['activity']['author'], 'the author is the token name, not a client field');

        $list = $this->toolset->call('crm_activities', ['subject_type' => 'contact', 'subject_id' => $cid], $write, $this->ctx);
        self::assertSame(1, $list['count']);
        self::assertSame('Discussed the engine.', $list['activities'][0]['body']);

        $del = $this->toolset->call('crm_activity_delete', ['id' => $out['activity']['id']], $write, $this->ctx);
        self::assertTrue($del['deleted']);
    }

    public function test_author_cannot_be_over_posted_on_an_activity(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');
        $c     = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada'], $write, $this->ctx);

        $out = $this->toolset->call('crm_activity_add', [
            'subject_type' => 'contact', 'subject_id' => $c['contact']['id'], 'body' => 'x', 'author' => 'spoofed-name',
        ], $write, $this->ctx);
        self::assertTrue($out['ok']);
        self::assertSame('crm-bot', $out['activity']['author'], 'a client-supplied author is ignored');
    }

    public function test_logging_against_a_missing_subject_comes_back_as_data(): void
    {
        $out = $this->toolset->call('crm_activity_add', [
            'subject_type' => 'contact', 'subject_id' => 999999, 'body' => 'ghost',
        ], $this->principal('nimbuscms.crm:write'), $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('invalid', $out['error']);
    }

    public function test_a_content_token_cannot_reach_deals_either(): void
    {
        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "crm_deals"');
        $this->toolset->call('crm_deals', [], $this->principal('*:read', '*:write'), $this->ctx);
    }

    public function test_deal_set_get_delete_round_trip_with_links(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');
        $c     = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada'], $write, $this->ctx);
        $o     = $this->toolset->call('crm_organization_set', ['name' => 'Acme'], $write, $this->ctx);

        $out = $this->toolset->call('crm_deal_set', [
            'title' => 'Engine build', 'value' => '1500.5', 'currency' => 'gbp', 'stage' => 'proposal',
            'contact_id' => $c['contact']['id'], 'org_id' => $o['organization']['id'],
        ], $write, $this->ctx);
        self::assertTrue($out['ok']);
        self::assertSame('1500.50', $out['deal']['value'], 'money is normalised to two places');
        self::assertSame('GBP', $out['deal']['currency'], 'currency is upper-cased');
        self::assertSame('Ada', $out['deal']['contact']);
        self::assertSame('Acme', $out['deal']['organization']);
        $dealId = $out['deal']['id'];

        $got = $this->toolset->call('crm_deal_get', ['id' => $dealId], $write, $this->ctx);
        self::assertSame('proposal', $got['deal']['stage']);

        // An activity can hang off the deal, and goes with it on delete.
        $this->toolset->call('crm_activity_add', ['subject_type' => 'deal', 'subject_id' => $dealId, 'body' => 'Sent proposal.'], $write, $this->ctx);
        self::assertSame(1, $this->toolset->call('crm_activities', ['subject_type' => 'deal', 'subject_id' => $dealId], $write, $this->ctx)['count']);

        $del = $this->toolset->call('crm_deal_delete', ['id' => $dealId], $write, $this->ctx);
        self::assertTrue($del['deleted']);
        self::assertSame([], $this->toolset->call('crm_activities', ['subject_type' => 'deal', 'subject_id' => $dealId], $write, $this->ctx)['activities'], 'the deal timeline goes with it');
    }

    public function test_deals_filter_by_status(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');
        $this->toolset->call('crm_deal_set', ['title' => 'Open one', 'status' => 'open'], $write, $this->ctx);
        $this->toolset->call('crm_deal_set', ['title' => 'Won one', 'status' => 'won'], $write, $this->ctx);

        self::assertSame(1, $this->toolset->call('crm_deals', ['status' => 'won'], $write, $this->ctx)['count']);
        self::assertSame(2, $this->toolset->call('crm_deals', [], $write, $this->ctx)['count']);
    }

    public function test_a_deal_without_a_title_comes_back_as_data(): void
    {
        $out = $this->toolset->call('crm_deal_set', ['value' => '100'], $this->principal('nimbuscms.crm:write'), $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('invalid', $out['error']);
    }

    public function test_a_bad_deal_value_comes_back_as_data(): void
    {
        $out = $this->toolset->call('crm_deal_set', ['title' => 'X', 'value' => '-5'], $this->principal('nimbuscms.crm:write'), $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('invalid', $out['error']);
    }

    public function test_a_content_token_cannot_reach_tags_either(): void
    {
        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "crm_tag_attach"');
        $this->toolset->call('crm_tag_attach', [], $this->principal('*:read', '*:write'), $this->ctx);
    }

    public function test_tag_attach_by_name_then_tagged_returns_the_records(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');
        $a = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada'], $write, $this->ctx)['contact']['id'];
        $b = $this->toolset->call('crm_contact_set', ['first_name' => 'Alan'], $write, $this->ctx)['contact']['id'];

        // Attaching a new tag by name creates it and applies it.
        $out = $this->toolset->call('crm_tag_attach', ['subject_type' => 'contact', 'subject_id' => $a, 'tag_name' => 'VIP'], $write, $this->ctx);
        self::assertTrue($out['ok']);
        self::assertTrue($out['attached']);
        $tagId = $out['tag_id'];

        // The same tag on a second contact, by id this time.
        $this->toolset->call('crm_tag_attach', ['subject_type' => 'contact', 'subject_id' => $b, 'tag_id' => $tagId], $write, $this->ctx);

        // Attaching again is idempotent (no second link).
        $again = $this->toolset->call('crm_tag_attach', ['subject_type' => 'contact', 'subject_id' => $a, 'tag_id' => $tagId], $write, $this->ctx);
        self::assertFalse($again['attached'], 'already tagged is a no-op');

        // "all contacts tagged VIP" resolves to full records.
        $tagged = $this->toolset->call('crm_tagged', ['subject_type' => 'contact', 'tag_id' => $tagId], $write, $this->ctx);
        self::assertSame(2, $tagged['count']);
        self::assertSame('Ada', $tagged['records'][0]['first_name']);

        // Detach one, and it drops out.
        $this->toolset->call('crm_tag_detach', ['subject_type' => 'contact', 'subject_id' => $a, 'tag_id' => $tagId], $write, $this->ctx);
        self::assertSame(1, $this->toolset->call('crm_tagged', ['subject_type' => 'contact', 'tag_id' => $tagId], $write, $this->ctx)['count']);
    }

    public function test_tagging_a_missing_subject_comes_back_as_data(): void
    {
        $out = $this->toolset->call('crm_tag_attach', ['subject_type' => 'contact', 'subject_id' => 999999, 'tag_name' => 'X'], $this->principal('nimbuscms.crm:write'), $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('invalid', $out['error']);
    }

    public function test_a_tag_can_span_types_and_delete_removes_it_everywhere(): void
    {
        $write = $this->principal('nimbuscms.crm:read', 'nimbuscms.crm:write');
        $c = $this->toolset->call('crm_contact_set', ['first_name' => 'Ada'], $write, $this->ctx)['contact']['id'];
        $d = $this->toolset->call('crm_deal_set', ['title' => 'Engine'], $write, $this->ctx)['deal']['id'];
        $tagId = $this->toolset->call('crm_tag_create', ['name' => 'Priority'], $write, $this->ctx)['tag']['id'];

        $this->toolset->call('crm_tag_attach', ['subject_type' => 'contact', 'subject_id' => $c, 'tag_id' => $tagId], $write, $this->ctx);
        $this->toolset->call('crm_tag_attach', ['subject_type' => 'deal', 'subject_id' => $d, 'tag_id' => $tagId], $write, $this->ctx);
        self::assertSame(2, $this->toolset->call('crm_tags', [], $write, $this->ctx)['tags'][0]['count'], 'the tag is used twice, across types');

        $this->toolset->call('crm_tag_delete', ['id' => $tagId], $write, $this->ctx);
        self::assertSame([], $this->toolset->call('crm_tags_for', ['subject_type' => 'contact', 'subject_id' => $c], $write, $this->ctx)['tags'], 'deleting a tag removes it everywhere');
    }
}
