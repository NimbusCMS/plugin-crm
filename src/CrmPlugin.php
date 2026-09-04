<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginContext;
use Nimbus\Plugin\PluginStorage;

/**
 * The official CRM plugin — contacts, and (in later slices) organizations,
 * activities and a deal pipeline. A back-office store of **PII**, so it declares
 * its own wildcard-immune capability (ADR 0015) and gates **every** surface —
 * admin pages (ADR 0020) and MCP tools (ADR 0016) — on it: a content `*:write`
 * token can never reach contact data. All on its **own tables** (ADR 0005),
 * touching no core data; no public/site surface at all.
 *
 * Slice 1: contacts (CRUD over the admin + MCP).
 */
final class CrmPlugin implements Plugin
{
    /** Matches extra.nimbus.id in composer.json. */
    public const ID = 'nimbuscms.crm';

    public function register(PluginContext $context): void
    {
        $context->migrations()->register('001_contacts', Schema::contacts());

        // Grantable, wildcard-immune: nimbuscms.crm:read / :write. Contact data is
        // PII — a content *:write token can never read or change it.
        $context->capabilities()->declare('CRM', ['read', 'write']);

        // Storage is taken lazily, so register() runs no query and loads without a database.
        $storage  = static fn (): PluginStorage => $context->storage();
        $contacts = new Contacts($storage);

        // The agent surface — every tool gates on nimbuscms.crm:read|write (ADR 0016).
        $context->mcp()->register(new CrmToolset($contacts));

        // Admin: search + list + create/edit form. Gated on nimbuscms.crm:write
        // (same as the write tools) so a content-only editor can't reach PII; the
        // handler gets the CSP nonce (2nd arg) and a CSRF token (3rd arg).
        $context->adminPages()->register(
            'crm',
            'Contacts',
            '👥',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new ContactsAdmin($contacts))->render($csrf, $r->query('ok') ?? $r->query('err'), $r->query('edit'), $r->query('q'), $nonce),
            self::ID . ':write',
        );

        $context->adminPages()->action('crm', 'contact-save', static function (Request $r) use ($contacts): Response {
            // Build the field set from a known allow-list only — never the raw
            // request — so no unexpected key (a forged id/timestamp) is assigned.
            $fields = [
                'first_name' => (string) ($r->input('first_name') ?? ''),
                'last_name'  => (string) ($r->input('last_name') ?? ''),
                'email'      => (string) ($r->input('email') ?? ''),
                'phone'      => (string) ($r->input('phone') ?? ''),
                'notes'      => (string) ($r->input('notes') ?? ''),
            ];
            $idIn = trim((string) ($r->input('id') ?? ''));
            $id   = ($idIn !== '' && ctype_digit($idIn)) ? (int) $idIn : null;
            try {
                $contacts->save($id, $fields, date('Y-m-d H:i:s'));
                return Response::redirect('/admin/crm?ok=saved');
            } catch (\InvalidArgumentException $e) {
                $msg = $e->getMessage();
                $code = str_contains($msg, 'email') ? 'bademail' : (str_contains($msg, 'name') ? 'noname' : 'invalid');
                return Response::redirect('/admin/crm?err=' . $code);
            } catch (\Throwable) {
                return Response::redirect('/admin/crm?err=invalid');
            }
        });

        $context->adminPages()->action('crm', 'contact-delete', static function (Request $r) use ($contacts): Response {
            $idIn = trim((string) ($r->input('id') ?? ''));
            if ($idIn !== '' && ctype_digit($idIn)) {
                $contacts->delete((int) $idIn);
            }
            return Response::redirect('/admin/crm?ok=deleted');
        });

        // Teach an MCP agent how to drive the CRM (ADR 0013).
        $context->skills()->register('CRM', Guide::text());
    }
}
