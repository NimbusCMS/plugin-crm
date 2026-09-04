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

        A back-office CRM: **contacts** (people), the **organizations** (companies) they
        belong to, and an **activity timeline** against either; a deal pipeline arrives in
        a later slice. It is **PII**, so every tool is gated by the `nimbuscms.crm`
        capability: a read needs `nimbuscms.crm:read`, a write needs `nimbuscms.crm:write`.
        A content `*:write` token cannot reach it, and a tool you lack the capability for
        is invisible.

        ## Contacts

        - `crm_contacts` — list contacts, or search by name/email (`q`).
        - `crm_contact_get` — one contact by `id`.
        - `crm_contact_set` — create (omit `id`) or update (with `id`). Fields:
          `first_name`, `last_name` (a contact needs at least one), `email` (validated),
          `phone`, `notes`, `org_id` (an existing organization to link to; blank to
          unlink). Only the fields you send change.
        - `crm_contact_delete` — remove a contact outright by `id` (the "forget" primitive).

        A contact read includes its `org_id` and the resolved `organization` name.

        ## Organizations

        - `crm_organizations` — list organizations, or search by name (`q`).
        - `crm_organization_get` — one organization by `id`.
        - `crm_organization_set` — create (omit `id`) or update (with `id`). Fields:
          `name` (required to create), `website`, `notes`. Only the fields you send change.
        - `crm_organization_delete` — remove an organization by `id`. Its contacts are
          **kept** — their `org_id` is simply cleared.

        Link a contact to a company by passing that company's `id` as the contact's
        `org_id`.

        ## Activities

        A timeline of dated, typed entries against a **subject** — a `contact` or an
        `organization`.

        - `crm_activities` — the timeline for one subject (`subject_type`, `subject_id`),
          most recent first.
        - `crm_activity_add` — log one. `subject_type` + `subject_id` (the subject must
          exist), `kind` (`note`/`call`/`email`/`meeting`/`other`, defaults to `note`),
          `body` (what happened), `occurred_at` (when; defaults to now). It is recorded
          under your token name.
        - `crm_activity_delete` — remove one activity by `id`.

        Deleting a contact or organization also removes its activities, so a "forget"
        leaves nothing behind. Values are stored as you send them and escaped when
        displayed; there is no public page for CRM data.
        MD;
    }
}
