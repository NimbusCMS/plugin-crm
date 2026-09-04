<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Auth\Authorizer;
use Nimbus\Database\Connection;
use Nimbus\Mcp\McpError;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\CrmToolset;
use NimbusCMS\Crm\Schema;
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
        foreach (Schema::contacts() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::CONTACT);

        $storage       = new PluginStorage($db);
        $this->toolset = new CrmToolset(new Contacts(static fn (): PluginStorage => $storage));
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
        self::assertSame(['crm_contacts', 'crm_contact_get', 'crm_contact_set', 'crm_contact_delete'], $names);
    }

    public function test_a_read_only_token_sees_only_the_read_tools(): void
    {
        $names = array_column($this->toolset->definitions($this->principal('nimbuscms.crm:read')), 'name');
        self::assertSame(['crm_contacts', 'crm_contact_get'], $names);
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
}
