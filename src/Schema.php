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
    public const CONTACT      = 'crm_contact';
    public const ORGANIZATION = 'crm_organization';
    public const ACTIVITY     = 'crm_activity';
    public const DEAL         = 'crm_deal';

    /** @return list<string> each statement individually idempotent (ADR 0005) */
    public static function contacts(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::CONTACT . ' (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                org_id     BIGINT UNSIGNED NULL,
                first_name VARCHAR(120) NOT NULL DEFAULT \'\',
                last_name  VARCHAR(120) NOT NULL DEFAULT \'\',
                email      VARCHAR(191) NULL,
                phone      VARCHAR(60) NULL,
                notes      TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_contact_name (last_name, first_name),
                INDEX idx_contact_email (email),
                INDEX idx_contact_org (org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /**
     * Organizations (companies), and the contact→org link. `org_id` on a contact is
     * a **soft reference** (no hard FK) validated at write and NULLed when its org is
     * deleted — a person is never destroyed because their company was removed.
     *
     * @return list<string> each statement individually idempotent (ADR 0005)
     */
    public static function organizations(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::ORGANIZATION . ' (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(200) NOT NULL,
                website    VARCHAR(255) NULL,
                notes      TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_org_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /**
     * The activity timeline — a dated, typed log entry against a **subject** (a
     * contact or an organization; `deal` is reserved in the column ENUM for the
     * deals slice, but the service rejects it until then). The subject link is
     * **polymorphic and soft**: `subject_type` is a write-time allow-list (never
     * interpolated) and `subject_id` is validated to exist on write; a subject's
     * delete removes its own activities so no dangling row is left behind.
     * Append-only (no `updated_at`): a timeline entry is added or removed, not
     * edited.
     *
     * @return list<string> each statement individually idempotent (ADR 0005)
     */
    public static function activities(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::ACTIVITY . " (
                id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subject_type ENUM('contact','organization','deal') NOT NULL,
                subject_id   BIGINT UNSIGNED NOT NULL,
                kind         ENUM('note','call','email','meeting','other') NOT NULL DEFAULT 'note',
                body         TEXT NULL,
                occurred_at  DATETIME NOT NULL,
                author       VARCHAR(191) NULL,
                created_at   DATETIME NOT NULL,
                INDEX idx_activity_subject (subject_type, subject_id, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    /**
     * The deal pipeline — an opportunity worth a `value`, moving through a `stage`
     * and ending `open`/`won`/`lost`. `contact_id`/`org_id` are **soft references**
     * (no hard FK) validated at write and NULLed when their contact/org is deleted,
     * so a deal is never destroyed — or left dangling — because a linked record was.
     * `stage` and `status` are write-time allow-lists (never interpolated); `value`
     * is a bounded, non-negative decimal.
     *
     * @return list<string> each statement individually idempotent (ADR 0005)
     */
    public static function deals(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::DEAL . " (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title      VARCHAR(200) NOT NULL,
                value      DECIMAL(18,2) NULL,
                currency   CHAR(3) NOT NULL DEFAULT 'USD',
                stage      ENUM('lead','qualified','proposal','negotiation') NOT NULL DEFAULT 'lead',
                status     ENUM('open','won','lost') NOT NULL DEFAULT 'open',
                contact_id BIGINT UNSIGNED NULL,
                org_id     BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_deal_status_stage (status, stage),
                INDEX idx_deal_contact (contact_id),
                INDEX idx_deal_org (org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}
