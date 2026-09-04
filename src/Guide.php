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
        belong to, an **activity timeline** against any of them, a **deal pipeline**, and
        **tags** you apply and filter by. It is **PII**, so every tool is gated by the
        `nimbuscms.crm` capability: a read needs `nimbuscms.crm:read`, a write needs
        `nimbuscms.crm:write`. A content `*:write` token cannot reach it, and a tool you
        lack the capability for is invisible.

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

        A timeline of dated, typed entries against a **subject** — a `contact`, an
        `organization` or a `deal`.

        - `crm_activities` — the timeline for one subject (`subject_type`, `subject_id`),
          most recent first.
        - `crm_activity_add` — log one. `subject_type` + `subject_id` (the subject must
          exist), `kind` (`note`/`call`/`email`/`meeting`/`other`, defaults to `note`),
          `body` (what happened), `occurred_at` (when; defaults to now). It is recorded
          under your token name.
        - `crm_activity_delete` — remove one activity by `id`.

        Deleting a contact, organization or deal also removes its activities, so a
        "forget" leaves nothing behind.

        ## Deals

        The sales pipeline — an opportunity with a `title`, an optional money `value`,
        the `stage` it sits in and a `status`.

        - `crm_deals` — list the pipeline; filter by `status` (`open`/`won`/`lost`) or
          search by title (`q`).
        - `crm_deal_get` — one deal by `id`.
        - `crm_deal_set` — create (omit `id`) or update (with `id`). Fields: `title`
          (required to create), `value` (non-negative, ≤ 2 decimals), `currency`
          (3-letter code, defaults USD), `stage` (`lead`/`qualified`/`proposal`/
          `negotiation`, defaults `lead`), `status` (`open`/`won`/`lost`, defaults
          `open`), `contact_id` and `org_id` (existing records to link; blank to unlink).
          Only the fields you send change.
        - `crm_deal_delete` — remove a deal by `id`, together with its activities.

        Deleting a contact or organization keeps any deal that referenced it — the link
        is simply cleared.

        ## Tags

        Labels applied to any contact, organization or deal, so you can group and filter
        them ("all contacts tagged VIP").

        - `crm_tags` — list every tag with its usage count.
        - `crm_tag_create` — create a tag by `name` (or return the one that already has
          that name).
        - `crm_tag_delete` — delete a tag by `id`; it is removed from everything it is
          on, but the records are kept.
        - `crm_tag_attach` — apply a tag to a subject: `subject_type`
          (`contact`/`organization`/`deal`) + `subject_id`, and either an existing
          `tag_id` or a `tag_name` (found or created). Idempotent.
        - `crm_tag_detach` — remove a tag (`tag_id`) from a subject.
        - `crm_tags_for` — the tags on one subject.
        - `crm_tagged` — every record of a type carrying a tag ("all contacts tagged X").

        Deleting a contact, organization or deal removes its tag links. Values are stored
        as you send them and escaped when displayed; there is no public page for CRM data.
        MD;
    }
}
