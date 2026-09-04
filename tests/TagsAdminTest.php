<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Schema;
use NimbusCMS\Crm\Tags;
use NimbusCMS\Crm\TagsAdmin;
use PHPUnit\Framework\TestCase;

/**
 * Tags in the admin: the management page renders tag names (author input) and must
 * escape them; the record block and filter bar are pure renderers, so they prove
 * escaping and that the forms/links carry the CSRF token and the subject.
 */
final class TagsAdminTest extends TestCase
{
    private Tags $tags;
    private TagsAdmin $admin;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach (Schema::tags() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::TAG);
        $db->execute('TRUNCATE ' . Schema::TAGGABLE);

        $storage     = new PluginStorage($db);
        $this->tags  = new Tags(static fn (): PluginStorage => $storage);
        $this->admin = new TagsAdmin($this->tags);
    }

    public function test_the_management_page_escapes_a_hostile_tag_name(): void
    {
        $this->tags->saveTag(null, '<script>alert(1)</script>', '2026-01-01 09:00:00');

        $html = $this->admin->render('CSRF123', null, null, 'n');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the tag name is escaped');
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('value="CSRF123"', $html, 'the CSRF token is in the forms');
    }

    public function test_the_block_escapes_a_hostile_tag_and_carries_subject_and_csrf(): void
    {
        $on  = [['id' => 1, 'name' => '"><img src=x onerror=alert(1)>']];
        $all = [['id' => 2, 'name' => 'VIP', 'count' => 0]];

        $html = TagsAdmin::block('CSRF123', 'crm', 'contact', 7, $on, $all, 'n');

        self::assertStringNotContainsString('<img src=x', $html, 'the tag name is escaped in the chip');
        self::assertStringContainsString('value="CSRF123"', $html);
        self::assertStringContainsString('action="/admin/crm/tag-attach"', $html);
        self::assertStringContainsString('value="contact"', $html, 'the subject type is posted');
        self::assertStringContainsString('value="7"', $html, 'the subject id is posted');
        self::assertStringContainsString('>VIP<', $html, 'an unused tag is offered to add');
    }

    public function test_the_filter_bar_marks_the_active_tag_and_hides_unused(): void
    {
        $all = [
            ['id' => 1, 'name' => 'VIP', 'count' => 3],
            ['id' => 2, 'name' => 'Unused', 'count' => 0],
        ];

        $html = TagsAdmin::filterBar('crm', $all, 1);

        self::assertStringContainsString('VIP', $html);
        self::assertStringNotContainsString('Unused', $html, 'a tag on nothing is not offered as a filter');
        self::assertStringContainsString('is-active', $html, 'the active tag is marked');
        self::assertStringContainsString('Clear', $html);
    }

    public function test_the_filter_bar_is_empty_when_there_are_no_tags(): void
    {
        self::assertSame('', TagsAdmin::filterBar('crm', [], null));
    }
}
