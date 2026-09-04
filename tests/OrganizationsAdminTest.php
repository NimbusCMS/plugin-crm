<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Organizations;
use NimbusCMS\Crm\OrganizationsAdmin;
use NimbusCMS\Crm\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The organizations admin renders author input (name/website/notes) to a
 * privileged operator — the one real risk is an un-escaped value (stored XSS).
 * This proves those values are escaped on the way out in both the list and the
 * edit form, and that the CSRF token reaches the forms.
 */
final class OrganizationsAdminTest extends TestCase
{
    private Organizations $organizations;
    private OrganizationsAdmin $admin;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach (Schema::organizations() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::ORGANIZATION);

        $storage             = new PluginStorage($db);
        $this->organizations = new Organizations(static fn (): PluginStorage => $storage);
        $this->admin         = new OrganizationsAdmin($this->organizations);
    }

    public function test_the_list_escapes_hostile_author_values(): void
    {
        $this->organizations->save(null, [
            'name'    => '<script>alert(1)</script>',
            'website' => '"><img src=x onerror=alert(1)>',
        ], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, null, null, 'test-nonce');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the name is escaped in the list');
        self::assertStringNotContainsString('<img src=x', $html, 'the website is escaped in the list');
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('value="CSRF123"', $html, 'the CSRF token is in the forms');
    }

    public function test_the_edit_form_escapes_the_loaded_values(): void
    {
        $id = $this->organizations->save(null, [
            'name'  => '"><script>alert(1)</script>',
            'notes' => '<b>note</b> & things',
        ], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, (string) $id, null, 'test-nonce');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the loaded name is escaped in the form');
        self::assertStringNotContainsString('<b>note</b>', $html, 'the loaded notes are escaped in the textarea');
        self::assertStringContainsString('&lt;b&gt;note&lt;/b&gt; &amp; things', $html);
        self::assertStringContainsString('Edit organization', $html);
    }
}
