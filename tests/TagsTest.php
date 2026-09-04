<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Crm\Contacts;
use NimbusCMS\Crm\Deals;
use NimbusCMS\Crm\Schema;
use NimbusCMS\Crm\Tags;
use PHPUnit\Framework\TestCase;

/**
 * The Tags service — the shared vocabulary and its normalized, polymorphic links,
 * with the discipline the review pinned: an allow-listed + existence-checked subject
 * (no dangling links), unique names, idempotent tagging, bound SQL, and a delete
 * that clears links without touching the subjects.
 */
final class TagsTest extends TestCase
{
    private Tags $tags;
    private Contacts $contacts;
    private Deals $deals;

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
        foreach ([Schema::CONTACT, Schema::ORGANIZATION, Schema::ACTIVITY, Schema::DEAL, Schema::TAG, Schema::TAGGABLE] as $t) {
            $db->execute('TRUNCATE ' . $t);
        }

        $storage        = new PluginStorage($db);
        $this->tags     = new Tags(static fn (): PluginStorage => $storage);
        $this->contacts = new Contacts(static fn (): PluginStorage => $storage);
        $this->deals    = new Deals(static fn (): PluginStorage => $storage);
    }

    private const NOW = '2026-01-01 09:00:00';

    public function test_create_rename_and_reject_duplicate(): void
    {
        $id = $this->tags->saveTag(null, 'VIP', self::NOW);
        self::assertSame('VIP', $this->tags->getTag($id)['name']);

        $this->tags->saveTag($id, 'Very Important', self::NOW);
        self::assertSame('Very Important', $this->tags->getTag($id)['name']);

        $this->tags->saveTag(null, 'Partner', self::NOW);
        $this->expectException(\InvalidArgumentException::class);
        $this->tags->saveTag($id, 'Partner', self::NOW); // clashes with another tag
    }

    public function test_a_tag_needs_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tags->saveTag(null, '   ', self::NOW);
    }

    public function test_find_or_create_is_case_insensitive(): void
    {
        $a = $this->tags->findOrCreate('VIP', self::NOW);
        $b = $this->tags->findOrCreate('vip', self::NOW);
        self::assertSame($a, $b, 'the same tag is reused, not duplicated');
    }

    public function test_all_tags_reports_usage_counts(): void
    {
        $cid   = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $tagId = $this->tags->findOrCreate('VIP', self::NOW);
        $this->tags->findOrCreate('Unused', self::NOW);
        $this->tags->attach('contact', $cid, $tagId, self::NOW);

        $all = $this->tags->allTags();
        $byName = [];
        foreach ($all as $t) {
            $byName[$t['name']] = $t['count'];
        }
        self::assertSame(1, $byName['VIP']);
        self::assertSame(0, $byName['Unused']);
    }

    public function test_tagging_is_idempotent_and_validated(): void
    {
        $cid   = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $tagId = $this->tags->findOrCreate('VIP', self::NOW);

        self::assertTrue($this->tags->attach('contact', $cid, $tagId, self::NOW));
        self::assertFalse($this->tags->attach('contact', $cid, $tagId, self::NOW), 'tagging twice is a no-op');
        self::assertCount(1, $this->tags->tagsFor('contact', $cid));
    }

    public function test_attach_rejects_an_unknown_subject_type(): void
    {
        $tagId = $this->tags->findOrCreate('VIP', self::NOW);
        $this->expectException(\InvalidArgumentException::class);
        $this->tags->attach('user', 1, $tagId, self::NOW);
    }

    public function test_attach_rejects_a_missing_subject(): void
    {
        $tagId = $this->tags->findOrCreate('VIP', self::NOW);
        $this->expectException(\InvalidArgumentException::class);
        $this->tags->attach('contact', 999999, $tagId, self::NOW);
    }

    public function test_attach_rejects_a_missing_tag(): void
    {
        $cid = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $this->expectException(\InvalidArgumentException::class);
        $this->tags->attach('contact', $cid, 999999, self::NOW);
    }

    public function test_a_tag_spans_types_and_ids_for_filters(): void
    {
        $cid   = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $did   = $this->deals->save(null, ['title' => 'Engine'], self::NOW);
        $tagId = $this->tags->findOrCreate('Priority', self::NOW);

        $this->tags->attach('contact', $cid, $tagId, self::NOW);
        $this->tags->attach('deal', $did, $tagId, self::NOW);

        self::assertSame([$cid], $this->tags->idsFor('contact', $tagId));
        self::assertSame([$did], $this->tags->idsFor('deal', $tagId));
    }

    public function test_detach_removes_one_link(): void
    {
        $cid   = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $tagId = $this->tags->findOrCreate('VIP', self::NOW);
        $this->tags->attach('contact', $cid, $tagId, self::NOW);

        self::assertSame(1, $this->tags->detach('contact', $cid, $tagId));
        self::assertSame([], $this->tags->tagsFor('contact', $cid));
    }

    public function test_deleting_a_tag_clears_its_links_but_keeps_subjects(): void
    {
        $cid   = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $tagId = $this->tags->findOrCreate('VIP', self::NOW);
        $this->tags->attach('contact', $cid, $tagId, self::NOW);

        self::assertTrue($this->tags->deleteTag($tagId));
        self::assertNull($this->tags->getTag($tagId));
        self::assertSame([], $this->tags->tagsFor('contact', $cid), 'the link is gone');
        self::assertNotNull($this->contacts->get($cid), 'the contact is kept');
    }

    public function test_clear_for_removes_every_link_on_a_subject(): void
    {
        $cid = $this->contacts->save(null, ['first_name' => 'Ada'], self::NOW);
        $this->tags->attach('contact', $cid, $this->tags->findOrCreate('A', self::NOW), self::NOW);
        $this->tags->attach('contact', $cid, $this->tags->findOrCreate('B', self::NOW), self::NOW);

        self::assertSame(2, $this->tags->clearFor('contact', $cid));
        self::assertSame([], $this->tags->tagsFor('contact', $cid));
    }
}
