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
 * Tools under the `crm` namespace: `contacts` (list/search) and `contact_get`
 * (reads); `contact_set` (create/update) and `contact_delete` (writes). The
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
    public function __construct(private Contacts $contacts, private Organizations $organizations)
    {
    }

    public function namespace(): string
    {
        return 'crm';
    }

    protected function tools(): array
    {
        $id    = ['type' => 'integer', 'description' => 'The contact id.'];
        $orgId = ['type' => 'integer', 'description' => 'The organization id.'];

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
