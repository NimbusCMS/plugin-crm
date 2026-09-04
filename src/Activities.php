<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Plugin\PluginStorage;

/**
 * Activities — the CRM timeline. A dated, typed entry (a note/call/email/meeting)
 * logged against a **subject**: a contact or an organization. The subject link is
 * polymorphic and carries the security review's sharp edges, so both are enforced
 * here at write:
 *
 *  - **`subject_type` is a write-time allow-list** ({@see SUBJECTS}), never
 *    interpolated into SQL. It selects the table the subject must live in, and is
 *    stored as a bound parameter — a contact, an organization or a deal.
 *  - **The subject must exist.** A bound `SELECT` against the mapped table rejects a
 *    dangling reference, so an activity can never point at a contact/org that isn't
 *    there.
 *  - **`kind` is an allow-list** too; **`body` is stored raw** (escaped on render)
 *    with a length cap; **`author`** is server-set by the caller (the MCP token
 *    name, or null in the admin) — never a client field, so it can't be spoofed.
 *
 * Append-only: an entry is added or deleted, not edited. A subject's own delete
 * purges its activities (see {@see Contacts::delete()} / {@see Organizations::delete()}),
 * so this service holds no orphaned PII.
 */
final class Activities
{
    public const SUBJECT_CONTACT      = 'contact';
    public const SUBJECT_ORGANIZATION = 'organization';
    public const SUBJECT_DEAL         = 'deal';

    /** subject_type → the table its id must exist in. The write-time allow-list. */
    private const SUBJECTS = [
        self::SUBJECT_CONTACT      => Schema::CONTACT,
        self::SUBJECT_ORGANIZATION => Schema::ORGANIZATION,
        self::SUBJECT_DEAL         => Schema::DEAL,
    ];

    /** @var list<string> */
    public const KINDS = ['note', 'call', 'email', 'meeting', 'other'];

    private const MAX_BODY   = 20000;
    private const MAX_AUTHOR = 191;

    /** @param \Closure():PluginStorage $storage resolved lazily, so construction runs no query */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * Log an activity against a subject. `author` is passed by the caller (never
     * read from `$fields`) so it cannot be over-posted. Returns the new activity id.
     *
     * @param array<string,mixed> $fields
     */
    public function add(array $fields, string $now, ?string $author = null): int
    {
        [$type, $id] = $this->subject($fields);
        $kind        = $this->kind($fields);
        $body        = $this->body($fields);
        $occurredAt  = $this->occurredAt($fields, $now);
        $who         = $this->author($author);

        return $this->storage()->insert(
            'INSERT INTO ' . Schema::ACTIVITY . ' (subject_type, subject_id, kind, body, occurred_at, author, created_at)
             VALUES (:type, :sid, :kind, :body, :occurred, :author, :created)',
            ['type' => $type, 'sid' => $id, 'kind' => $kind, 'body' => $body, 'occurred' => $occurredAt, 'author' => $who, 'created' => $now],
        );
    }

    /**
     * The timeline for one subject, most-recent first. `$type` is validated to the
     * allow-list; an unknown type is an empty timeline, not an error.
     *
     * @return list<array{id:int,subject_type:string,subject_id:int,kind:string,body:?string,occurred_at:string,author:?string,created_at:string}>
     */
    public function forSubject(string $type, int $id): array
    {
        if (!isset(self::SUBJECTS[$type])) {
            return [];
        }
        $rows = $this->storage()->select(
            'SELECT id, subject_type, subject_id, kind, body, occurred_at, author, created_at
             FROM ' . Schema::ACTIVITY . ' WHERE subject_type = :type AND subject_id = :id
             ORDER BY occurred_at DESC, id DESC',
            ['type' => $type, 'id' => $id],
        );
        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return array{id:int,subject_type:string,subject_id:int,kind:string,body:?string,occurred_at:string,author:?string,created_at:string}|null
     */
    public function get(int $id): ?array
    {
        $row = $this->storage()->selectOne(
            'SELECT id, subject_type, subject_id, kind, body, occurred_at, author, created_at
             FROM ' . Schema::ACTIVITY . ' WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** Delete one activity outright by id; returns the number of rows removed (0 if none). */
    public function delete(int $id): int
    {
        return $this->storage()->execute('DELETE FROM ' . Schema::ACTIVITY . ' WHERE id = :id', ['id' => $id]);
    }

    // --- validation / hydration -----------------------------------------

    /**
     * Resolve and validate the subject: an allow-listed `subject_type` and a
     * `subject_id` that exists in the mapped table.
     *
     * @param array<string,mixed> $fields
     * @return array{0:string,1:int}
     */
    private function subject(array $fields): array
    {
        $type = trim((string) ($fields['subject_type'] ?? ''));
        if (!isset(self::SUBJECTS[$type])) {
            throw new \InvalidArgumentException('"subject_type" must be one of: ' . implode(', ', array_keys(self::SUBJECTS)) . '.');
        }
        $raw = trim((string) ($fields['subject_id'] ?? ''));
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            throw new \InvalidArgumentException('"subject_id" must be a positive whole number.');
        }
        $id = (int) $raw;
        if ($this->storage()->selectOne('SELECT id FROM ' . self::SUBJECTS[$type] . ' WHERE id = :id', ['id' => $id]) === null) {
            throw new \InvalidArgumentException("No {$type} with id {$id}.");
        }
        return [$type, $id];
    }

    /** @param array<string,mixed> $fields */
    private function kind(array $fields): string
    {
        if (!array_key_exists('kind', $fields)) {
            return 'note';
        }
        $kind = trim((string) $fields['kind']);
        if ($kind === '') {
            return 'note';
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('"kind" must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        return $kind;
    }

    /** @param array<string,mixed> $fields */
    private function body(array $fields): ?string
    {
        $v = trim((string) ($fields['body'] ?? ''));
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > self::MAX_BODY) {
            throw new \InvalidArgumentException('"body" must be ' . self::MAX_BODY . ' characters or fewer.');
        }
        return $v;
    }

    /**
     * When the activity happened. Absent → now. Accepts a full datetime or an
     * `datetime-local` value (`T` separator, no seconds); a sloppy value is
     * rejected rather than silently reinterpreted.
     *
     * @param array<string,mixed> $fields
     */
    private function occurredAt(array $fields, string $now): string
    {
        $raw = str_replace('T', ' ', trim((string) ($fields['occurred_at'] ?? '')));
        if ($raw === '') {
            return $now;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($d !== false && $d->format($fmt) === $raw) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        throw new \InvalidArgumentException('"occurred_at" must be a valid date and time.');
    }

    private function author(?string $author): ?string
    {
        if ($author === null) {
            return null;
        }
        $v = trim($author);
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, self::MAX_AUTHOR);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,subject_type:string,subject_id:int,kind:string,body:?string,occurred_at:string,author:?string,created_at:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id'   => (int) $row['subject_id'],
            'kind'         => (string) $row['kind'],
            'body'         => $row['body'] === null ? null : (string) $row['body'],
            'occurred_at'  => (string) $row['occurred_at'],
            'author'       => $row['author'] === null ? null : (string) $row['author'],
            'created_at'   => (string) $row['created_at'],
        ];
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
