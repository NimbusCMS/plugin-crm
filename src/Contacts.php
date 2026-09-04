<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Plugin\PluginStorage;

/**
 * Contacts — the people the CRM tracks. A thin service over the plugin's own
 * {@see Schema::CONTACT} table (ADR 0005), with the write discipline the security
 * review pinned:
 *
 *  - **Store raw, escape on render.** `first_name`/`last_name`/`email`/`phone`/
 *    `notes` are kept byte-exact; the admin escapes on output (`View::e`). This
 *    class never HTML-encodes on the way in.
 *  - **Field allow-list.** A row is built only from known keys; the `id` is the
 *    addressed key (never mass-assigned) and timestamps are server-set — so no
 *    forged column can be written.
 *  - **Validation.** A contact needs a name (first and/or last); an email, if
 *    given, must be well-formed; every field has a length cap.
 *  - **Bound SQL everywhere**, including an escaped `LIKE` for search.
 *  - **Total delete** — the row is removed outright (the GDPR "forget" primitive);
 *    later slices that reference a contact must clean up their own rows too.
 *
 * All access is capability-gated by the callers (admin page + MCP tools on
 * `nimbuscms.crm:read`/`:write`); this service holds no PII behind a public door.
 */
final class Contacts
{
    private const MAX_NAME  = 120;
    private const MAX_EMAIL = 191;
    private const MAX_PHONE = 60;
    private const MAX_NOTES = 10000;

    /** @param \Closure():PluginStorage $storage resolved lazily, so construction runs no query */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * Create (id null) or update (id given) a contact from an allow-listed field
     * set; unknown keys are ignored. Unset fields keep their stored value on an
     * update, or take their column default on a create. Returns the contact id.
     *
     * @param array<string,mixed> $fields
     */
    public function save(?int $id, array $fields, string $now): int
    {
        $existing = $id !== null ? $this->get($id) : null;
        if ($id !== null && $existing === null) {
            throw new \InvalidArgumentException("No contact with id {$id}.");
        }

        $first = $this->name($fields, $existing, 'first_name');
        $last  = $this->name($fields, $existing, 'last_name');
        if ($first === '' && $last === '') {
            throw new \InvalidArgumentException('A contact needs a first or last name.');
        }
        $email = $this->email($fields, $existing);
        $phone = $this->optStr($fields, 'phone', $existing, self::MAX_PHONE);
        $notes = $this->optStr($fields, 'notes', $existing, self::MAX_NOTES);
        $orgId = $this->orgId($fields, $existing);

        if ($id === null) {
            return $this->storage()->insert(
                'INSERT INTO ' . Schema::CONTACT . ' (org_id, first_name, last_name, email, phone, notes, created_at, updated_at)
                 VALUES (:org, :first, :last, :email, :phone, :notes, :created, :updated)',
                ['org' => $orgId, 'first' => $first, 'last' => $last, 'email' => $email, 'phone' => $phone, 'notes' => $notes, 'created' => $now, 'updated' => $now],
            );
        }

        $this->storage()->execute(
            'UPDATE ' . Schema::CONTACT . ' SET org_id = :org, first_name = :first, last_name = :last, email = :email, phone = :phone, notes = :notes, updated_at = :now WHERE id = :id',
            ['org' => $orgId, 'first' => $first, 'last' => $last, 'email' => $email, 'phone' => $phone, 'notes' => $notes, 'now' => $now, 'id' => $id],
        );
        return $id;
    }

    /**
     * One contact by id, or null. Returned raw (unescaped) — the render layer
     * escapes.
     *
     * @return array{id:int,org_id:?int,organization:?string,first_name:string,last_name:string,email:?string,phone:?string,notes:?string,created_at:string,updated_at:string}|null
     */
    public function get(int $id): ?array
    {
        $row = $this->storage()->selectOne(
            'SELECT c.id, c.org_id, c.first_name, c.last_name, c.email, c.phone, c.notes, c.created_at, c.updated_at, o.name AS organization
             FROM ' . Schema::CONTACT . ' c LEFT JOIN ' . Schema::ORGANIZATION . ' o ON o.id = c.org_id
             WHERE c.id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Contacts for the admin list / MCP, most-recently-updated first, optionally
     * filtered to those whose name or email contains `$q` (a bound, wildcard-escaped
     * LIKE — never string-built SQL).
     *
     * @return list<array{id:int,org_id:?int,organization:?string,first_name:string,last_name:string,email:?string,phone:?string,notes:?string,created_at:string,updated_at:string}>
     */
    public function all(?string $q = null): array
    {
        $select = 'SELECT c.id, c.org_id, c.first_name, c.last_name, c.email, c.phone, c.notes, c.created_at, c.updated_at, o.name AS organization
                   FROM ' . Schema::CONTACT . ' c LEFT JOIN ' . Schema::ORGANIZATION . ' o ON o.id = c.org_id';

        $q = $q !== null ? trim($q) : '';
        if ($q === '') {
            $rows = $this->storage()->select($select . ' ORDER BY c.updated_at DESC, c.id DESC');
            return array_map($this->hydrate(...), $rows);
        }

        // Escape LIKE wildcards so a `%`/`_` in the term is a literal, and bind it.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
        $rows = $this->storage()->select(
            $select . " WHERE c.first_name LIKE :q ESCAPE '\\\\' OR c.last_name LIKE :q2 ESCAPE '\\\\' OR c.email LIKE :q3 ESCAPE '\\\\'
              ORDER BY c.updated_at DESC, c.id DESC",
            ['q' => $like, 'q2' => $like, 'q3' => $like],
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** Delete a contact outright by id; returns the number of rows removed (0 if none). */
    public function delete(int $id): int
    {
        return $this->storage()->execute('DELETE FROM ' . Schema::CONTACT . ' WHERE id = :id', ['id' => $id]);
    }

    // --- validation / hydration -----------------------------------------

    /**
     * A name part (first_name/last_name): trimmed, length-capped. Absent on an
     * update keeps the stored value; absent on a create is ''.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function name(array $fields, ?array $existing, string $key): string
    {
        if (!array_key_exists($key, $fields)) {
            return $existing !== null ? (string) $existing[$key] : '';
        }
        $v = trim((string) $fields[$key]);
        if (mb_strlen($v) > self::MAX_NAME) {
            throw new \InvalidArgumentException('A name must be ' . self::MAX_NAME . ' characters or fewer.');
        }
        return $v;
    }

    /**
     * A nullable, well-formed email. Empty/absent → null; a malformed value is
     * rejected rather than stored.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function email(array $fields, ?array $existing): ?string
    {
        if (!array_key_exists('email', $fields)) {
            return $existing !== null ? ($existing['email'] === null ? null : (string) $existing['email']) : null;
        }
        $v = trim((string) $fields['email']);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > self::MAX_EMAIL || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("\"{$v}\" is not a valid email address.");
        }
        return $v;
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
     * The organization a contact belongs to: null, or an id that must exist in this
     * install (a soft ref, checked at write so a dangling id can't be stored). Absent
     * on an update keeps the stored value.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function orgId(array $fields, ?array $existing): ?int
    {
        if (!array_key_exists('org_id', $fields)) {
            return $existing !== null ? ($existing['org_id'] === null ? null : (int) $existing['org_id']) : null;
        }
        $raw = trim((string) $fields['org_id']);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            throw new \InvalidArgumentException('"org_id" must be a positive whole number or blank.');
        }
        $id = (int) $raw;
        if ($this->storage()->selectOne('SELECT id FROM ' . Schema::ORGANIZATION . ' WHERE id = :id', ['id' => $id]) === null) {
            throw new \InvalidArgumentException("No organization with id {$id}.");
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,org_id:?int,organization:?string,first_name:string,last_name:string,email:?string,phone:?string,notes:?string,created_at:string,updated_at:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'org_id'       => $row['org_id'] === null ? null : (int) $row['org_id'],
            'organization' => ($row['organization'] ?? null) === null ? null : (string) $row['organization'],
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
            'email'      => $row['email'] === null ? null : (string) $row['email'],
            'phone'      => $row['phone'] === null ? null : (string) $row['phone'],
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
