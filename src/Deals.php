<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Plugin\PluginStorage;

/**
 * Deals — the pipeline. An opportunity with a `title`, an optional money `value`,
 * a `stage` it sits in and a `status` (open/won/lost), optionally linked to a
 * contact and/or an organization. Same write discipline as the rest of the CRM:
 *
 *  - **Field allow-list** — a row is built only from known keys; `id`/timestamps are
 *    never mass-assigned.
 *  - **Allow-listed enums** — `stage` ({@see STAGES}) and `status` ({@see STATUSES})
 *    are validated against a fixed set, never interpolated into SQL. (Stages are a
 *    generic v1 set; a configurable pipeline is a later enhancement.)
 *  - **Money is bounded** — `value` is a non-negative decimal with at most two
 *    places, capped to the column's `DECIMAL(18,2)`; `currency` is a 3-letter code.
 *  - **Soft links** — `contact_id`/`org_id` are validated to exist at write; a
 *    deleted contact/org NULLs them (see {@see Contacts::delete()} /
 *    {@see Organizations::delete()}) rather than destroying the deal.
 *  - **Bound SQL everywhere**, including an escaped `LIKE` on the title.
 *  - **Total delete** — the deal and its activity timeline go together, atomically.
 */
final class Deals
{
    /** @var list<string> the pipeline columns, in order */
    public const STAGES = ['lead', 'qualified', 'proposal', 'negotiation'];

    /** @var list<string> */
    public const STATUSES = ['open', 'won', 'lost'];

    private const MAX_TITLE = 200;
    // DECIMAL(18,2): up to 16 integer digits before the point.
    private const MAX_VALUE_INT_DIGITS = 16;

    /** @param \Closure():PluginStorage $storage resolved lazily, so construction runs no query */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * Create (id null) or update (id given) a deal from an allow-listed field set;
     * unknown keys are ignored. Returns the deal id.
     *
     * @param array<string,mixed> $fields
     */
    public function save(?int $id, array $fields, string $now): int
    {
        $existing = $id !== null ? $this->get($id) : null;
        if ($id !== null && $existing === null) {
            throw new \InvalidArgumentException("No deal with id {$id}.");
        }

        $title     = $this->title($fields, $existing);
        $value     = $this->value($fields, $existing);
        $currency  = $this->currency($fields, $existing);
        $stage     = $this->enum($fields, $existing, 'stage', self::STAGES, 'lead');
        $status    = $this->enum($fields, $existing, 'status', self::STATUSES, 'open');
        $contactId = $this->link($fields, $existing, 'contact_id', Schema::CONTACT);
        $orgId     = $this->link($fields, $existing, 'org_id', Schema::ORGANIZATION);

        $params = ['title' => $title, 'value' => $value, 'currency' => $currency, 'stage' => $stage, 'status' => $status, 'contact' => $contactId, 'org' => $orgId];

        if ($id === null) {
            return $this->storage()->insert(
                'INSERT INTO ' . Schema::DEAL . ' (title, value, currency, stage, status, contact_id, org_id, created_at, updated_at)
                 VALUES (:title, :value, :currency, :stage, :status, :contact, :org, :created, :updated)',
                $params + ['created' => $now, 'updated' => $now],
            );
        }

        $this->storage()->execute(
            'UPDATE ' . Schema::DEAL . ' SET title = :title, value = :value, currency = :currency, stage = :stage, status = :status, contact_id = :contact, org_id = :org, updated_at = :updated WHERE id = :id',
            $params + ['updated' => $now, 'id' => $id],
        );
        return $id;
    }

    /**
     * @return array{id:int,title:string,value:?string,currency:string,stage:string,status:string,contact_id:?int,contact:?string,org_id:?int,organization:?string,created_at:string,updated_at:string}|null
     */
    public function get(int $id): ?array
    {
        $row = $this->storage()->selectOne(
            $this->selectExpr() . ' WHERE d.id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Deals for the board / MCP, optionally filtered by an allow-listed `$status`
     * and/or a bound, wildcard-escaped title search. Ordered by pipeline stage then
     * most-recently-updated, so the board can bucket them in column order.
     *
     * @return list<array{id:int,title:string,value:?string,currency:string,stage:string,status:string,contact_id:?int,contact:?string,org_id:?int,organization:?string,created_at:string,updated_at:string}>
     */
    public function all(?string $q = null, ?string $status = null): array
    {
        $where  = [];
        $params = [];

        if ($status !== null && $status !== '' && in_array($status, self::STATUSES, true)) {
            $where[]          = 'd.status = :status';
            $params['status'] = $status;
        }
        $q = $q !== null ? trim($q) : '';
        if ($q !== '') {
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
            $where[]     = "d.title LIKE :q ESCAPE '\\\\'";
        }

        $sql = $this->selectExpr();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY FIELD(d.stage, 'lead', 'qualified', 'proposal', 'negotiation'), d.updated_at DESC, d.id DESC";

        return array_map($this->hydrate(...), $this->storage()->select($sql, $params));
    }

    /**
     * Delete a deal and its activity timeline, atomically. Returns the number of
     * deal rows removed (0 if none).
     */
    public function delete(int $id): int
    {
        return (int) $this->storage()->transaction(function () use ($id): int {
            $this->storage()->execute(
                'DELETE FROM ' . Schema::ACTIVITY . ' WHERE subject_type = :type AND subject_id = :id',
                ['type' => Activities::SUBJECT_DEAL, 'id' => $id],
            );
            $this->storage()->execute(
                'DELETE FROM ' . Schema::TAGGABLE . ' WHERE taggable_type = :type AND taggable_id = :id',
                ['type' => Activities::SUBJECT_DEAL, 'id' => $id],
            );
            return $this->storage()->execute('DELETE FROM ' . Schema::DEAL . ' WHERE id = :id', ['id' => $id]);
        });
    }

    // --- validation / hydration -----------------------------------------

    private function selectExpr(): string
    {
        return 'SELECT d.id, d.title, d.value, d.currency, d.stage, d.status, d.contact_id, d.org_id,
                       TRIM(CONCAT(COALESCE(c.first_name, \'\'), \' \', COALESCE(c.last_name, \'\'))) AS contact,
                       o.name AS organization, d.created_at, d.updated_at
                FROM ' . Schema::DEAL . ' d
                LEFT JOIN ' . Schema::CONTACT . ' c ON c.id = d.contact_id
                LEFT JOIN ' . Schema::ORGANIZATION . ' o ON o.id = d.org_id';
    }

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function title(array $fields, ?array $existing): string
    {
        if (!array_key_exists('title', $fields)) {
            if ($existing !== null) {
                return (string) $existing['title'];
            }
            throw new \InvalidArgumentException('A deal needs a title.');
        }
        $title = trim((string) $fields['title']);
        if ($title === '') {
            throw new \InvalidArgumentException('A deal needs a title.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new \InvalidArgumentException('A deal title must be ' . self::MAX_TITLE . ' characters or fewer.');
        }
        return $title;
    }

    /**
     * A non-negative money value with at most two decimal places, or null. Absent on
     * an update keeps the stored value.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function value(array $fields, ?array $existing): ?string
    {
        if (!array_key_exists('value', $fields)) {
            return $existing !== null ? ($existing['value'] === null ? null : (string) $existing['value']) : null;
        }
        $raw = str_replace([',', ' '], '', trim((string) $fields['value']));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,' . self::MAX_VALUE_INT_DIGITS . '}(\.\d{1,2})?$/', $raw) !== 1) {
            throw new \InvalidArgumentException('"value" must be a non-negative amount with up to two decimal places.');
        }
        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function currency(array $fields, ?array $existing): string
    {
        if (!array_key_exists('currency', $fields)) {
            return $existing !== null ? (string) $existing['currency'] : 'USD';
        }
        $raw = trim((string) $fields['currency']);
        if ($raw === '') {
            return 'USD';
        }
        if (preg_match('/^[A-Za-z]{3}$/', $raw) !== 1) {
            throw new \InvalidArgumentException('"currency" must be a 3-letter code (e.g. USD).');
        }
        return strtoupper($raw);
    }

    /**
     * A value from a fixed allow-list; never interpolated into SQL. Absent on an
     * update keeps the stored value; absent on a create takes `$default`.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     * @param list<string>             $allowed
     */
    private function enum(array $fields, ?array $existing, string $key, array $allowed, string $default): string
    {
        if (!array_key_exists($key, $fields)) {
            return $existing !== null ? (string) $existing[$key] : $default;
        }
        $v = trim((string) $fields[$key]);
        if ($v === '') {
            return $default;
        }
        if (!in_array($v, $allowed, true)) {
            throw new \InvalidArgumentException("\"{$key}\" must be one of: " . implode(', ', $allowed) . '.');
        }
        return $v;
    }

    /**
     * A soft link (contact_id/org_id): null, or an id that must exist in `$table`.
     * Absent on an update keeps the stored value.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function link(array $fields, ?array $existing, string $key, string $table): ?int
    {
        if (!array_key_exists($key, $fields)) {
            return $existing !== null ? ($existing[$key] === null ? null : (int) $existing[$key]) : null;
        }
        $raw = trim((string) $fields[$key]);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            throw new \InvalidArgumentException("\"{$key}\" must be a positive whole number or blank.");
        }
        $linkId = (int) $raw;
        if ($this->storage()->selectOne('SELECT id FROM ' . $table . ' WHERE id = :id', ['id' => $linkId]) === null) {
            $what = $table === Schema::CONTACT ? 'contact' : 'organization';
            throw new \InvalidArgumentException("No {$what} with id {$linkId}.");
        }
        return $linkId;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,title:string,value:?string,currency:string,stage:string,status:string,contact_id:?int,contact:?string,org_id:?int,organization:?string,created_at:string,updated_at:string}
     */
    private function hydrate(array $row): array
    {
        $contact = isset($row['contact']) && trim((string) $row['contact']) !== '' ? (string) $row['contact'] : null;

        return [
            'id'           => (int) $row['id'],
            'title'        => (string) $row['title'],
            'value'        => $row['value'] === null ? null : (string) $row['value'],
            'currency'     => (string) $row['currency'],
            'stage'        => (string) $row['stage'],
            'status'       => (string) $row['status'],
            'contact_id'   => $row['contact_id'] === null ? null : (int) $row['contact_id'],
            'contact'      => $contact,
            'org_id'       => $row['org_id'] === null ? null : (int) $row['org_id'],
            'organization' => ($row['organization'] ?? null) === null ? null : (string) $row['organization'],
            'created_at'   => (string) $row['created_at'],
            'updated_at'   => (string) $row['updated_at'],
        ];
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
