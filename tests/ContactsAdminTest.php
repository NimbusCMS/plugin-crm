<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Activities;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\ContactsAdmin;
use NimbusCMS\Crm\Organizations;
use NimbusCMS\Crm\Schema;
use NimbusCMS\Crm\Tags;
use PHPUnit\Framework\TestCase;

/**
 * The admin renders PII to a privileged operator — the one real risk is an
 * un-escaped value (stored XSS). Contact names, emails and free-text notes are
 * author input, so this proves they are escaped on the way out, in both the list
 * and the edit form, and that the CSRF token reaches the forms.
 */
final class ContactsAdminTest extends TestCase
{
    private Contacts $contacts;
    private Activities $activities;
    private ContactsAdmin $admin;

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
        $db->execute('TRUNCATE ' . Schema::TAG);
        $db->execute('TRUNCATE ' . Schema::TAGGABLE);

        $storage          = new PluginStorage($db);
        $this->contacts   = new Contacts(static fn (): PluginStorage => $storage);
        $this->activities = new Activities(static fn (): PluginStorage => $storage);
        $this->admin      = new ContactsAdmin(
            $this->contacts,
            new Organizations(static fn (): PluginStorage => $storage),
            $this->activities,
            new Tags(static fn (): PluginStorage => $storage),
        );
    }

    public function test_the_list_escapes_hostile_author_values(): void
    {
        $this->contacts->save(null, [
            'first_name' => '<script>alert(1)</script>',
            'last_name'  => 'X',
            'notes'      => '<img src=x onerror=alert(1)>',
        ], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, null, null, 'test-nonce');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the name is escaped in the list');
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('value="CSRF123"', $html, 'the CSRF token is in the forms');
    }

    public function test_the_edit_form_escapes_the_loaded_values(): void
    {
        $id = $this->contacts->save(null, [
            'first_name' => '"><script>alert(1)</script>',
            'last_name'  => 'Y',
            'notes'      => '<b>note</b> & things',
        ], '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, (string) $id, null, 'test-nonce');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the loaded name is escaped in the form');
        self::assertStringNotContainsString('<b>note</b>', $html, 'the loaded notes are escaped in the textarea');
        self::assertStringContainsString('&lt;b&gt;note&lt;/b&gt; &amp; things', $html);
        self::assertStringContainsString('Edit contact', $html);
    }

    public function test_the_timeline_shows_only_when_editing_and_escapes_activity_bodies(): void
    {
        $id = $this->contacts->save(null, ['first_name' => 'Ada'], '2026-01-01 09:00:00');

        // No timeline block on the plain list view.
        self::assertStringNotContainsString('Log activity', $this->admin->render('CSRF123', null, null, null, 'n'));

        $this->activities->add(['subject_type' => 'contact', 'subject_id' => (string) $id, 'body' => '<script>alert(1)</script>'], '2026-01-01 09:00:00');
        $html = $this->admin->render('CSRF123', null, (string) $id, null, 'n');

        self::assertStringContainsString('Log activity', $html, 'the add-activity form shows on the edit page');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'a hostile activity body is escaped');
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
