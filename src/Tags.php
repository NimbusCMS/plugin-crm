<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Plugin\PluginStorage;

/**
 * Tags — a shared vocabulary of labels and the normalized, polymorphic links that
 * apply them to a contact, organization or deal. Same discipline as the rest of the
 * CRM:
 *
 *  - **Allow-listed subject type** ({@see TAGGABLES}) — never interpolated; it also
 *    selects the table a subject must exist in, so a link can't dangle.
 *  - **Store raw, escape on render**; a tag name is length-capped and unique
 *    (case-insensitive).
 *  - **Bound SQL everywhere**; tagging is idempotent (a unique link backstops races).
 *  - **Total delete** — deleting a tag clears its links (never the subjects);
 *    deleting a subject clears its links (see {@see clearFor()}, called from each
 *    entity's delete).
 */
final class Tags
{
    /** taggable_type → the table its id must exist in. The write-time allow-list. */
    private const TAGGABLES = [
        Activities::SUBJECT_CONTACT      => Schema::CONTACT,
        Activities::SUBJECT_ORGANIZATION => Schema::ORGANIZATION,
        Activities::SUBJECT_DEAL         => Schema::DEAL,
    ];

    private const MAX_NAME = 60;

    /** @param \Closure():PluginStorage $storage resolved lazily, so construction runs no query */
    public function __construct(private \Closure $storage)
    {
    }

    // --- tags ------------------------------------------------------------

    /**
     * Create (id null) or rename (id given) a tag. The name is required, capped and
     * unique (case-insensitive) — a clash is rejected as data. Returns the tag id.
     */
    public function saveTag(?int $id, string $name, string $now): int
    {
        $name = $this->name($name);
        $clash = $this->storage()->selectOne(
            'SELECT id FROM ' . Schema::TAG . ' WHERE name = :name' . ($id !== null ? ' AND id <> :id' : ''),
            $id !== null ? ['name' => $name, 'id' => $id] : ['name' => $name],
        );
        if ($clash !== null) {
            throw new \InvalidArgumentException("A tag named \"{$name}\" already exists.");
        }

        if ($id === null) {
            return $this->storage()->insert(
                'INSERT INTO ' . Schema::TAG . ' (name, created_at, updated_at) VALUES (:name, :created, :updated)',
                ['name' => $name, 'created' => $now, 'updated' => $now],
            );
        }
        if ($this->getTag($id) === null) {
            throw new \InvalidArgumentException("No tag with id {$id}.");
        }
        $this->storage()->execute(
            'UPDATE ' . Schema::TAG . ' SET name = :name, updated_at = :now WHERE id = :id',
            ['name' => $name, 'now' => $now, 'id' => $id],
        );
        return $id;
    }

    /** Find a tag by name (case-insensitive), or create it. Returns the tag id. */
    public function findOrCreate(string $name, string $now): int
    {
        $name = $this->name($name);
        $row  = $this->storage()->selectOne('SELECT id FROM ' . Schema::TAG . ' WHERE name = :name', ['name' => $name]);
        if ($row !== null) {
            return (int) $row['id'];
        }
        return $this->storage()->insert(
            'INSERT INTO ' . Schema::TAG . ' (name, created_at, updated_at) VALUES (:name, :created, :updated)',
            ['name' => $name, 'created' => $now, 'updated' => $now],
        );
    }

    /**
     * @return array{id:int,name:string,created_at:string,updated_at:string}|null
     */
    public function getTag(int $id): ?array
    {
        $row = $this->storage()->selectOne('SELECT id, name, created_at, updated_at FROM ' . Schema::TAG . ' WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * All tags, name-ordered, each with its total usage count across every subject
     * type — for the management page and the filter bars.
     *
     * @return list<array{id:int,name:string,count:int}>
     */
    public function allTags(): array
    {
        $rows = $this->storage()->select(
            'SELECT t.id, t.name, COUNT(tg.id) AS cnt
             FROM ' . Schema::TAG . ' t LEFT JOIN ' . Schema::TAGGABLE . ' tg ON tg.tag_id = t.id
             GROUP BY t.id, t.name ORDER BY t.name',
        );
        return array_map(static fn (array $r): array => [
            'id'    => (int) $r['id'],
            'name'  => (string) $r['name'],
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    /** Delete a tag and every link to it, atomically. Returns true when a tag was removed. */
    public function deleteTag(int $id): bool
    {
        return (bool) $this->storage()->transaction(function () use ($id): bool {
            $this->storage()->execute('DELETE FROM ' . Schema::TAGGABLE . ' WHERE tag_id = :id', ['id' => $id]);
            return $this->storage()->execute('DELETE FROM ' . Schema::TAG . ' WHERE id = :id', ['id' => $id]) > 0;
        });
    }

    // --- tagging ---------------------------------------------------------

    /**
     * Apply a tag to a subject. The subject type is allow-listed and the subject and
     * tag must exist. Idempotent: returns true if a new link was made, false if it
     * was already tagged.
     */
    public function attach(string $type, int $subjectId, int $tagId, string $now): bool
    {
        $table = $this->table($type);
        if ($this->getTag($tagId) === null) {
            throw new \InvalidArgumentException("No tag with id {$tagId}.");
        }
        if ($this->storage()->selectOne('SELECT id FROM ' . $table . ' WHERE id = :id', ['id' => $subjectId]) === null) {
            throw new \InvalidArgumentException("No {$type} with id {$subjectId}.");
        }
        $exists = $this->storage()->selectOne(
            'SELECT id FROM ' . Schema::TAGGABLE . ' WHERE tag_id = :tag AND taggable_type = :type AND taggable_id = :sid',
            ['tag' => $tagId, 'type' => $type, 'sid' => $subjectId],
        );
        if ($exists !== null) {
            return false;
        }
        $this->storage()->insert(
            'INSERT INTO ' . Schema::TAGGABLE . ' (tag_id, taggable_type, taggable_id, created_at) VALUES (:tag, :type, :sid, :created)',
            ['tag' => $tagId, 'type' => $type, 'sid' => $subjectId, 'created' => $now],
        );
        return true;
    }

    /** Remove a tag from a subject. Returns the number of links removed (0 if it wasn't tagged). */
    public function detach(string $type, int $subjectId, int $tagId): int
    {
        if (!isset(self::TAGGABLES[$type])) {
            return 0;
        }
        return $this->storage()->execute(
            'DELETE FROM ' . Schema::TAGGABLE . ' WHERE tag_id = :tag AND taggable_type = :type AND taggable_id = :sid',
            ['tag' => $tagId, 'type' => $type, 'sid' => $subjectId],
        );
    }

    /**
     * The tags on one subject, name-ordered.
     *
     * @return list<array{id:int,name:string}>
     */
    public function tagsFor(string $type, int $subjectId): array
    {
        if (!isset(self::TAGGABLES[$type])) {
            return [];
        }
        $rows = $this->storage()->select(
            'SELECT t.id, t.name FROM ' . Schema::TAGGABLE . ' tg
             JOIN ' . Schema::TAG . ' t ON t.id = tg.tag_id
             WHERE tg.taggable_type = :type AND tg.taggable_id = :sid ORDER BY t.name',
            ['type' => $type, 'sid' => $subjectId],
        );
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * The ids of subjects of `$type` carrying `$tagId` — the "everything tagged X"
     * filter (a real indexed join). An unknown type yields none.
     *
     * @return list<int>
     */
    public function idsFor(string $type, int $tagId): array
    {
        if (!isset(self::TAGGABLES[$type])) {
            return [];
        }
        $rows = $this->storage()->select(
            'SELECT taggable_id FROM ' . Schema::TAGGABLE . ' WHERE taggable_type = :type AND tag_id = :tag',
            ['type' => $type, 'tag' => $tagId],
        );
        return array_map(static fn (array $r): int => (int) $r['taggable_id'], $rows);
    }

    /** Remove every tag link on a subject — the cleanup an entity's delete calls. Returns links removed. */
    public function clearFor(string $type, int $subjectId): int
    {
        if (!isset(self::TAGGABLES[$type])) {
            return 0;
        }
        return $this->storage()->execute(
            'DELETE FROM ' . Schema::TAGGABLE . ' WHERE taggable_type = :type AND taggable_id = :sid',
            ['type' => $type, 'sid' => $subjectId],
        );
    }

    // --- validation ------------------------------------------------------

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A tag needs a name.');
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('A tag name must be ' . self::MAX_NAME . ' characters or fewer.');
        }
        return $name;
    }

    private function table(string $type): string
    {
        if (!isset(self::TAGGABLES[$type])) {
            throw new \InvalidArgumentException('"type" must be one of: ' . implode(', ', array_keys(self::TAGGABLES)) . '.');
        }
        return self::TAGGABLES[$type];
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
