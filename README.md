# NimbusCMS CRM

A CRM for [NimbusCMS](https://github.com/NimbusCMS/nimbus) — contacts, and (in
later slices) organizations, activities and a deal pipeline. An official plugin,
built like the others: it declares a capability, gates its admin **and** MCP tools
on it, and lives on its own tables, touching no core data.

## Private by design

Contact data is **PII**, so the plugin declares a wildcard-immune capability
(`nimbuscms.crm`, [ADR 0015](https://github.com/NimbusCMS/nimbus/blob/main/docs/adr/0015-plugin-management-capability.md))
and gates **every** surface on it:

- a **read** needs `nimbuscms.crm:read`, a **write** needs `nimbuscms.crm:write`;
- a content `*:write` token can **never** reach contacts — the capability is
  exact-or-`admin`, not reachable by the content wildcard;
- there is **no public surface** — no site pages, no `/ext` routes, no headless
  API. The CRM is back-office only.

Values are **stored raw and escaped on render**; search is bound and
wildcard-escaped; a delete is **total** (the "forget me" primitive).

## What's here (Slice 1)

**Contacts** — people, with a first/last name, email, phone and notes.

- **Admin** — `/admin/crm`: search, list, create/edit, delete (gated on
  `nimbuscms.crm:write`, CSRF-protected).
- **MCP** — the `crm` toolset, so an agent can run the CRM:
  - `crm_contacts` (list/search), `crm_contact_get` — reads;
  - `crm_contact_set` (create/update), `crm_contact_delete` — writes.

Organizations, activities, deals/pipeline and tags land in later slices.

## Install

```bash
composer require nimbuscms/crm
```

Then grant a role the `crm:read`/`crm:write` capability (or `admin`). Plugins are
enabled-by-default once installed; `config/plugins.php` is the opt-out.

## Develop

```bash
composer install
composer check   # phpstan (level 6) + phpunit
composer format  # php-cs-fixer
```

Tests use a real MySQL (`TEST_DB_HOST`, `TEST_DB_PORT`, …), as in CI.
