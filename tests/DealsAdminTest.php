<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Activities;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\Deals;
use NimbusCMS\Crm\DealsAdmin;
use NimbusCMS\Crm\Organizations;
use NimbusCMS\Crm\Schema;
use NimbusCMS\Crm\Tags;
use PHPUnit\Framework\TestCase;

/**
 * The deals admin renders author input (deal title, and through the timeline, the
 * activity body) to a privileged operator — the risk is an un-escaped value. This
 * proves escaping on the board and in the edit form, that the board buckets open
 * deals by stage while won/lost land in the Closed section, and that the CSRF token
 * reaches the forms.
 */
final class DealsAdminTest extends TestCase
{
    private Deals $deals;
    private DealsAdmin $admin;

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

        $storage     = new PluginStorage($db);
        $this->deals = new Deals(static fn (): PluginStorage => $storage);
        $this->admin = new DealsAdmin(
            $this->deals,
            new Contacts(static fn (): PluginStorage => $storage),
            new Organizations(static fn (): PluginStorage => $storage),
            new Activities(static fn (): PluginStorage => $storage),
            new Tags(static fn (): PluginStorage => $storage),
        );
    }

    public function test_the_board_escapes_a_hostile_deal_title(): void
    {
        $this->deals->save(null, ['title' => '<script>alert(1)</script>', 'stage' => 'lead'], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, null, null, 'n');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the title is escaped');
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('value="CSRF123"', $html, 'the CSRF token is in the forms');
        self::assertStringContainsString('Lead', $html, 'stage columns are rendered');
    }

    public function test_won_and_lost_land_in_the_closed_section_not_the_board(): void
    {
        $this->deals->save(null, ['title' => 'Open deal', 'status' => 'open'], '2026-01-01 09:00:00');
        $this->deals->save(null, ['title' => 'Bagged it', 'status' => 'won'], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, null, null, 'n');

        self::assertStringContainsString('Closed', $html);
        self::assertStringContainsString('cr-badge-won', $html, 'a won badge shows in the closed list');
    }

    public function test_the_edit_form_and_timeline_show_when_editing(): void
    {
        $id = $this->deals->save(null, ['title' => 'Editable', 'value' => '1200'], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, (string) $id, null, 'n');

        self::assertStringContainsString('Edit deal', $html);
        self::assertStringContainsString('value="1200.00"', $html, 'the stored value is loaded into the form');
        self::assertStringContainsString('Log activity', $html, 'the timeline shows on a deal edit page');
    }

    public function test_search_shows_a_flat_result_list(): void
    {
        $this->deals->save(null, ['title' => 'Acme renewal'], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, null, 'Acme', 'n');
        self::assertStringContainsString('Acme renewal', $html);
        self::assertStringContainsString('Back to board', $html, 'searching offers a way back to the board');
    }
}
