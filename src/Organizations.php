<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Plugin\PluginStorage;

/**
 * Organizations (companies) the CRM tracks, and the source of the contact→org link.
 * Same write discipline as {@see Contacts}: a field allow-list, name required,
 * length caps, store-raw/escape-on-render, bound + wildcard-escaped search.
 *
 * Deleting an organization is **total but non-destructive to people**: in one
 * transaction it NULLs `org_id` on every contact that pointed at it, then removes
 * the org — a contact is never deleted because its company was removed.
 */
final class Organizations
{
    private const MAX_NAME    = 200;
    private const MAX_WEBSITE = 255;
    private const MAX_NOTES   = 10000;

    /** @param \Closure():PluginStorage $storage resolved lazily, so construction runs no query */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * Create (id null) or update (id given) an organization from an allow-listed
     * field set; unknown keys are ignored. Returns the org id.
     *
     * @param array<string,mixed> $fields
     */
    public function save(?int $id, array $fields, string $now): int
    {
        $existing = $id !== null ? $this->get($id) : null;
        if ($id !== null && $existing === null) {
            throw new \InvalidArgumentException("No organization with id {$id}.");
        }

        $name    = $this->name($fields, $existing);
        $website = $this->optStr($fields, 'website', $existing, self::MAX_WEBSITE);
        $notes   = $this->optStr($fields, 'notes', $existing, self::MAX_NOTES);

        if ($id === null) {
            return $this->storage()->insert(
                'INSERT INTO ' . Schema::ORGANIZATION . ' (name, website, notes, created_at, updated_at)
                 VALUES (:name, :website, :notes, :created, :updated)',
                ['name' => $name, 'website' => $website, 'notes' => $notes, 'created' => $now, 'updated' => $now],
            );
        }

        $this->storage()->execute(
            'UPDATE ' . Schema::ORGANIZATION . ' SET name = :name, website = :website, notes = :notes, updated_at = :now WHERE id = :id',
            ['name' => $name, 'website' => $website, 'notes' => $notes, 'now' => $now, 'id' => $id],
        );
        return $id;
    }

    /**
     * @return array{id:int,name:string,website:?string,notes:?string,created_at:string,updated_at:string}|null
     */
    public function get(int $id): ?array
    {
        $row = $this->storage()->selectOne(
            'SELECT id, name, website, notes, created_at, updated_at FROM ' . Schema::ORGANIZATION . ' WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Organizations for the admin list / MCP, name-first, optionally filtered to
     * those whose name contains `$q` (a bound, wildcard-escaped LIKE).
     *
     * @return list<array{id:int,name:string,website:?string,notes:?string,created_at:string,updated_at:string}>
     */
    public function all(?string $q = null): array
    {
        $q = $q !== null ? trim($q) : '';
        if ($q === '') {
            $rows = $this->storage()->select(
                'SELECT id, name, website, notes, created_at, updated_at FROM ' . Schema::ORGANIZATION . ' ORDER BY name',
            );
            return array_map($this->hydrate(...), $rows);
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
        $rows = $this->storage()->select(
            'SELECT id, name, website, notes, created_at, updated_at FROM ' . Schema::ORGANIZATION .
            " WHERE name LIKE :q ESCAPE '\\\\' ORDER BY name",
            ['q' => $like],
        );
        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Delete an organization atomically: its contacts and deals are **kept** (their
     * `org_id` is NULLed, never cascaded into a delete), its own activity timeline is
     * removed (those belong to the company, not to any surviving person or deal), and
     * then the org itself. Returns true when an org was removed.
     */
    public function delete(int $id): bool
    {
        return (bool) $this->storage()->transaction(function () use ($id): bool {
            $this->storage()->execute('UPDATE ' . Schema::CONTACT . ' SET org_id = NULL WHERE org_id = :id', ['id' => $id]);
            // A deal outlives the company, but must not dangle: clear the link.
            $this->storage()->execute('UPDATE ' . Schema::DEAL . ' SET org_id = NULL WHERE org_id = :id', ['id' => $id]);
            $this->storage()->execute(
                'DELETE FROM ' . Schema::ACTIVITY . ' WHERE subject_type = :type AND subject_id = :id',
                ['type' => Activities::SUBJECT_ORGANIZATION, 'id' => $id],
            );
            return $this->storage()->execute('DELETE FROM ' . Schema::ORGANIZATION . ' WHERE id = :id', ['id' => $id]) > 0;
        });
    }

    /** Whether an organization id exists — the soft-ref check {@see Contacts} uses on write. */
    public function exists(int $id): bool
    {
        return $this->storage()->selectOne('SELECT id FROM ' . Schema::ORGANIZATION . ' WHERE id = :id', ['id' => $id]) !== null;
    }

    // --- validation / hydration -----------------------------------------

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function name(array $fields, ?array $existing): string
    {
        if (!array_key_exists('name', $fields)) {
            if ($existing !== null) {
                return (string) $existing['name'];
            }
            throw new \InvalidArgumentException('An organization name is required.');
        }
        $name = trim((string) $fields['name']);
        if ($name === '') {
            throw new \InvalidArgumentException('An organization name is required.');
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('An organization name must be ' . self::MAX_NAME . ' characters or fewer.');
        }
        return $name;
    }

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function optStr(array $fields, string $key, ?array $existing, int $max): ?string
    {
        if (!array_key_exists($key, $fields)) {
            return $existing !== null ? ($existing[$key] === null ? null : (string) $existing[$key]) : null;
        }
        $v = trim((string) $fields[$key]);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new \InvalidArgumentException("\"{$key}\" must be {$max} characters or fewer.");
        }
        return $v;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,name:string,website:?string,notes:?string,created_at:string,updated_at:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'website'    => $row['website'] === null ? null : (string) $row['website'],
            'notes'      => $row['notes'] === null ? null : (string) $row['notes'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
