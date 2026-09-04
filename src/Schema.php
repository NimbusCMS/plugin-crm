<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * The CRM's own tables (ADR 0005 — prefixed away from core `nb_*`).
 *
 * Slice 1 is **contacts** — the people a business keeps track of. It is a
 * back-office store of **PII** (names, emails, phones), so nothing here is ever
 * public: every read/write is gated by the `nimbuscms.crm` capability (ADR 0015),
 * values are stored raw and escaped on render, and a delete is total (the
 * "forget me" primitive). Organizations, activities, deals and tags arrive in
 * later slices as their own additive migrations.
 */
final class Schema
{
    public const CONTACT = 'crm_contact';

    /** @return list<string> each statement individually idempotent (ADR 0005) */
    public static function contacts(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::CONTACT . ' (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(120) NOT NULL DEFAULT \'\',
                last_name  VARCHAR(120) NOT NULL DEFAULT \'\',
                email      VARCHAR(191) NULL,
                phone      VARCHAR(60) NULL,
                notes      TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_contact_name (last_name, first_name),
                INDEX idx_contact_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}
