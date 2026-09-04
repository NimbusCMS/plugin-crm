<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Mcp\PluginTool;
use Nimbus\Mcp\PluginToolset;

/**
 * The CRM over MCP — an agent is a first-class operator of the CRM (ADR 0009/0016).
 *
 * Tools under the `crm` namespace cover contacts, organizations, the activity
 * timeline and the deal pipeline — reads (`*s`/`*_get`/`activities`) and writes
 * (`*_set`/`*_add`/`*_delete`). The
 * {@see PluginToolset} base gates every one on this plugin's own `nimbuscms.crm`
 * capability (ADR 0015/0016) — a write tool needs `:write`, a read needs `:read`,
 * both **unreachable by a content `*:write` token** and invisible (a denied tool
 * reports as unknown) — so this class writes no authorization code and never
 * exposes PII behind the content wildcard.
 *
 * Input/validation errors come back as **data**, not a 500, so an agent can
 * correct a bad email rather than crash.
 */
final class CrmToolset extends PluginToolset
{
    public function __construct(
        private Contacts $contacts,
        private Organizations $organizations,
        private Activities $activities,
        private Deals $deals,
    ) {
    }

    public function namespace(): string
    {
        return 'crm';
    }

    protected function tools(): array
    {
        $id       = ['type' => 'integer', 'description' => 'The contact id.'];
        $orgId    = ['type' => 'integer', 'description' => 'The organization id.'];
        $subjects = [Activities::SUBJECT_CONTACT, Activities::SUBJECT_ORGANIZATION, Activities::SUBJECT_DEAL];

        return [
            new PluginTool('contacts', 'read', 'List contacts (all, or those whose name or email matches a search).', [
                'type'       => 'object',
                'properties' => ['q' => ['type' => 'string', 'description' => 'Optional search over name and email.']],
            ], $this->contacts(...)),

            new PluginTool('contact_get', 'read', 'One contact by id, or none.', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => $id],
            ], $this->contactGet(...)),

            new PluginTool('contact_set', 'write', 'Create a contact (omit id) or update one (with id). Only the fields you send change; the rest keep their stored value.', [
                'type'       => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer', 'description' => 'Existing contact id to update; omit to create.'],
                    'first_name' => ['type' => 'string', 'description' => 'Given name. A contact needs a first and/or last name.'],
                    'last_name'  => ['type' => 'string', 'description' => 'Family name.'],
                    'email'      => ['type' => 'string', 'description' => 'Email address (validated). Optional.'],
                    'phone'      => ['type' => 'string', 'description' => 'Phone number. Optional.'],
                    'notes'      => ['type' => 'string', 'description' => 'Free-text notes (plain text). Optional.'],
                    'org_id'     => ['type' => 'integer', 'description' => 'An existing organization id to link the contact to. Optional; blank to unlink.'],
                ],
            ], $this->contactSet(...)),

            new PluginTool('contact_delete', 'write', 'Delete a contact outright by id (the "forget" primitive).', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => $id],
            ], $this->contactDelete(...)),

            new PluginTool('organizations', 'read', 'List organizations (all, or those whose name matches a search).', [
                'type'       => 'object',
                'properties' => ['q' => ['type' => 'string', 'description' => 'Optional search over the organization name.']],
            ], $this->organizations(...)),

            new PluginTool('organization_get', 'read', 'One organization by id, or none.', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => $orgId],
            ], $this->organizationGet(...)),

            new PluginTool('organization_set', 'write', 'Create an organization (omit id) or update one (with id). Only the fields you send change.', [
                'type'       => 'object',
                'properties' => [
                    'id'      => ['type' => 'integer', 'description' => 'Existing organization id to update; omit to create.'],
                    'name'    => ['type' => 'string', 'description' => 'Organization name (required to create).'],
                    'website' => ['type' => 'string', 'description' => 'Website URL. Optional.'],
                    'notes'   => ['type' => 'string', 'description' => 'Free-text notes (plain text). Optional.'],
                ],
            ], $this->organizationSet(...)),

            new PluginTool('organization_delete', 'write', 'Delete an organization by id; its contacts are kept but unlinked (org_id cleared).', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => $orgId],
            ], $this->organizationDelete(...)),

            new PluginTool('activities', 'read', 'The activity timeline for one subject (a contact, organization or deal), most recent first.', [
                'type'       => 'object',
                'required'   => ['subject_type', 'subject_id'],
                'properties' => [
                    'subject_type' => ['type' => 'string', 'enum' => $subjects, 'description' => 'Whose timeline: "contact", "organization" or "deal".'],
                    'subject_id'   => ['type' => 'integer', 'description' => 'The contact, organization or deal id.'],
                ],
            ], $this->activities(...)),

            new PluginTool('activity_add', 'write', 'Log an activity (a note/call/email/meeting) against a contact, organization or deal. Recorded under your token name.', [
                'type'       => 'object',
                'required'   => ['subject_type', 'subject_id'],
                'properties' => [
                    'subject_type' => ['type' => 'string', 'enum' => $subjects, 'description' => 'What to attach it to: "contact", "organization" or "deal".'],
                    'subject_id'   => ['type' => 'integer', 'description' => 'The existing contact, organization or deal id.'],
                    'kind'         => ['type' => 'string', 'enum' => Activities::KINDS, 'description' => 'The kind of activity. Defaults to "note".'],
                    'body'         => ['type' => 'string', 'description' => 'What happened (plain text). Optional.'],
                    'occurred_at'  => ['type' => 'string', 'description' => 'When it happened, "YYYY-MM-DD HH:MM[:SS]". Defaults to now.'],
                ],
            ], $this->activityAdd(...)),

            new PluginTool('activity_delete', 'write', 'Delete one activity outright by id.', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The activity id.']],
            ], $this->activityDelete(...)),

            new PluginTool('deals', 'read', 'List deals in the pipeline (optionally filtered by status, or searched by title).', [
                'type'       => 'object',
                'properties' => [
                    'q'      => ['type' => 'string', 'description' => 'Optional search over the deal title.'],
                    'status' => ['type' => 'string', 'enum' => Deals::STATUSES, 'description' => 'Optional filter: "open", "won" or "lost".'],
                ],
            ], $this->deals(...)),

            new PluginTool('deal_get', 'read', 'One deal by id, or none.', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The deal id.']],
            ], $this->dealGet(...)),

            new PluginTool('deal_set', 'write', 'Create a deal (omit id) or update one (with id). Only the fields you send change.', [
                'type'       => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer', 'description' => 'Existing deal id to update; omit to create.'],
                    'title'      => ['type' => 'string', 'description' => 'Deal title (required to create).'],
                    'value'      => ['type' => 'string', 'description' => 'Money value, non-negative, up to 2 decimals. Optional; blank to clear.'],
                    'currency'   => ['type' => 'string', 'description' => '3-letter currency code. Defaults to USD.'],
                    'stage'      => ['type' => 'string', 'enum' => Deals::STAGES, 'description' => 'Pipeline stage. Defaults to "lead".'],
                    'status'     => ['type' => 'string', 'enum' => Deals::STATUSES, 'description' => 'Deal status. Defaults to "open".'],
                    'contact_id' => ['type' => 'integer', 'description' => 'An existing contact to link. Optional; blank to unlink.'],
                    'org_id'     => ['type' => 'integer', 'description' => 'An existing organization to link. Optional; blank to unlink.'],
                ],
            ], $this->dealSet(...)),

            new PluginTool('deal_delete', 'write', 'Delete a deal by id, together with its activity timeline.', [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The deal id.']],
            ], $this->dealDelete(...)),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function contacts(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $list = $this->contacts->all($this->nullableStr($a, 'q'));
        return ['contacts' => $list, 'count' => count($list)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function contactGet(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        return ['id' => $id, 'contact' => $this->contacts->get($id)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function contactSet(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a): array {
            // save() reads only its allow-listed keys from the arguments — an
            // unknown key (or a forged id/timestamp) is ignored.
            $id = $this->contacts->save($this->nullableInt($a, 'id'), $a, $this->now());
            return ['ok' => true, 'contact' => $this->contacts->get($id)];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function contactDelete(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        $removed = $this->contacts->delete($id);
        return ['ok' => true, 'deleted' => $removed > 0];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function organizations(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $list = $this->organizations->all($this->nullableStr($a, 'q'));
        return ['organizations' => $list, 'count' => count($list)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function organizationGet(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        return ['id' => $id, 'organization' => $this->organizations->get($id)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function organizationSet(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a): array {
            $id = $this->organizations->save($this->nullableInt($a, 'id'), $a, $this->now());
            return ['ok' => true, 'organization' => $this->organizations->get($id)];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function organizationDelete(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        return ['ok' => true, 'deleted' => $this->organizations->delete($id)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function activities(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $type = (string) ($a['subject_type'] ?? '');
        $id   = $this->requireInt($a, 'subject_id');
        $list = $this->activities->forSubject($type, $id);
        return ['activities' => $list, 'count' => count($list)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function activityAdd(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a, $p): array {
            // author is the token's own name — server-set, never read from args.
            $id = $this->activities->add($a, $this->now(), $p->name);
            return ['ok' => true, 'activity' => $this->activities->get($id)];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function activityDelete(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        return ['ok' => true, 'deleted' => $this->activities->delete($id) > 0];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function deals(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $list = $this->deals->all($this->nullableStr($a, 'q'), $this->nullableStr($a, 'status'));
        return ['deals' => $list, 'count' => count($list)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function dealGet(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        return ['id' => $id, 'deal' => $this->deals->get($id)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function dealSet(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a): array {
            $id = $this->deals->save($this->nullableInt($a, 'id'), $a, $this->now());
            return ['ok' => true, 'deal' => $this->deals->get($id)];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function dealDelete(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $id = $this->requireInt($a, 'id');
        return ['ok' => true, 'deleted' => $this->deals->delete($id) > 0];
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param \Closure():array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function guard(\Closure $work): array
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'invalid', 'message' => $e->getMessage()];
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $a */
    private function requireInt(array $a, string $key): int
    {
        $v = $this->nullableInt($a, $key);
        if ($v === null) {
            throw new \InvalidArgumentException("\"{$key}\" is required.");
        }
        return $v;
    }

    /** @param array<string,mixed> $a */
    private function nullableInt(array $a, string $key): ?int
    {
        $v = $a[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d+$/', trim($v)) === 1) {
            return (int) trim($v);
        }
        throw new \InvalidArgumentException("\"{$key}\" must be a whole number.");
    }

    /** @param array<string,mixed> $a */
    private function nullableStr(array $a, string $key): ?string
    {
        $v = $a[$key] ?? null;
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
