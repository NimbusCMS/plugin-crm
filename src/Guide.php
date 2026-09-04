<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * The agent guide for the CRM (ADR 0013), served as `nimbus://guide/plugin/nimbuscms.crm`.
 * Reference documentation, not instructions — static, bounded, world-readable to any
 * valid token, so it carries no secrets or per-tenant data.
 */
final class Guide
{
    public static function text(): string
    {
        return <<<'MD'
        # CRM

        A back-office CRM: **contacts** (people), and — in later slices — organizations,
        activities and a deal pipeline. It is **PII**, so every tool is gated by the
        `nimbuscms.crm` capability: a read needs `nimbuscms.crm:read`, a write needs
        `nimbuscms.crm:write`. A content `*:write` token cannot reach it, and a tool you
        lack the capability for is invisible.

        ## Contacts

        - `crm_contacts` — list contacts, or search by name/email (`q`).
        - `crm_contact_get` — one contact by `id`.
        - `crm_contact_set` — create (omit `id`) or update (with `id`). Fields:
          `first_name`, `last_name` (a contact needs at least one), `email` (validated),
          `phone`, `notes`. Only the fields you send change.
        - `crm_contact_delete` — remove a contact outright by `id` (the "forget" primitive).

        Values are stored as you send them and escaped when displayed; there is no public
        page for CRM data.
        MD;
    }
}
