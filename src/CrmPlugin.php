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
 * Slice 1: contacts. Slice 2: organizations + the contact→org link. Slice 3: the
 * activity timeline against a contact or an organization.
 */
final class CrmPlugin implements Plugin
{
    /** Matches extra.nimbus.id in composer.json. */
    public const ID = 'nimbuscms.crm';

    public function register(PluginContext $context): void
    {
        $context->migrations()->register('001_contacts', Schema::contacts());
        $context->migrations()->register('002_organizations', Schema::organizations());
        $context->migrations()->register('003_activities', Schema::activities());

        // Grantable, wildcard-immune: nimbuscms.crm:read / :write. Contact data is
        // PII — a content *:write token can never read or change it.
        $context->capabilities()->declare('CRM', ['read', 'write']);

        // Storage is taken lazily, so register() runs no query and loads without a database.
        $storage       = static fn (): PluginStorage => $context->storage();
        $contacts      = new Contacts($storage);
        $organizations = new Organizations($storage);
        $activities    = new Activities($storage);

        // The agent surface — every tool gates on nimbuscms.crm:read|write (ADR 0016).
        $context->mcp()->register(new CrmToolset($contacts, $organizations, $activities));

        // Admin: search + list + create/edit form. Gated on nimbuscms.crm:write
        // (same as the write tools) so a content-only editor can't reach PII; the
        // handler gets the CSP nonce (2nd arg) and a CSRF token (3rd arg).
        $context->adminPages()->register(
            'crm',
            'Contacts',
            '👥',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new ContactsAdmin($contacts, $organizations, $activities))->render($csrf, $r->query('ok') ?? $r->query('err'), $r->query('edit'), $r->query('q'), $nonce),
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
                'org_id'     => (string) ($r->input('org_id') ?? ''),
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

        // Organizations: the companies contacts belong to. Same crm:write gate.
        $context->adminPages()->register(
            'crm-organizations',
            'Organizations',
            '🏢',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new OrganizationsAdmin($organizations, $activities))->render($csrf, $r->query('ok') ?? $r->query('err'), $r->query('edit'), $r->query('q'), $nonce),
            self::ID . ':write',
        );

        $context->adminPages()->action('crm-organizations', 'org-save', static function (Request $r) use ($organizations): Response {
            $fields = [
                'name'    => (string) ($r->input('name') ?? ''),
                'website' => (string) ($r->input('website') ?? ''),
                'notes'   => (string) ($r->input('notes') ?? ''),
            ];
            $idIn = trim((string) ($r->input('id') ?? ''));
            $id   = ($idIn !== '' && ctype_digit($idIn)) ? (int) $idIn : null;
            try {
                $organizations->save($id, $fields, date('Y-m-d H:i:s'));
                return Response::redirect('/admin/crm-organizations?ok=saved');
            } catch (\InvalidArgumentException $e) {
                $code = str_contains($e->getMessage(), 'name') ? 'noname' : 'invalid';
                return Response::redirect('/admin/crm-organizations?err=' . $code);
            } catch (\Throwable) {
                return Response::redirect('/admin/crm-organizations?err=invalid');
            }
        });

        $context->adminPages()->action('crm-organizations', 'org-delete', static function (Request $r) use ($organizations): Response {
            $idIn = trim((string) ($r->input('id') ?? ''));
            if ($idIn !== '' && ctype_digit($idIn)) {
                $organizations->delete((int) $idIn);
            }
            return Response::redirect('/admin/crm-organizations?ok=deleted');
        });

        // Activities are logged inline on a contact or an organization. The same two
        // actions serve both record pages (each inherits its page's crm:write + CSRF
        // gate); the subject on the form decides where we redirect back to. The admin
        // sets no author — there is no spoofable author field (an MCP add records the
        // token name; see CrmToolset).
        $back = static function (Request $r): string {
            $page = ($r->input('subject_type') === Activities::SUBJECT_ORGANIZATION) ? 'crm-organizations' : 'crm';
            $sid  = trim((string) ($r->input('subject_id') ?? ''));
            $edit = ($sid !== '' && ctype_digit($sid)) ? '?edit=' . $sid . '&' : '?';
            return '/admin/' . $page . $edit;
        };

        $addActivity = static function (Request $r) use ($activities, $back): Response {
            $base = $back($r);
            $fields = [
                'subject_type' => (string) ($r->input('subject_type') ?? ''),
                'subject_id'   => (string) ($r->input('subject_id') ?? ''),
                'kind'         => (string) ($r->input('kind') ?? 'note'),
                'body'         => (string) ($r->input('body') ?? ''),
                'occurred_at'  => (string) ($r->input('occurred_at') ?? ''),
            ];
            try {
                $activities->add($fields, date('Y-m-d H:i:s'), null);
                return Response::redirect($base . 'ok=activity');
            } catch (\Throwable) {
                return Response::redirect($base . 'err=activitybad');
            }
        };

        $deleteActivity = static function (Request $r) use ($activities, $back): Response {
            $idIn = trim((string) ($r->input('id') ?? ''));
            if ($idIn !== '' && ctype_digit($idIn)) {
                $activities->delete((int) $idIn);
            }
            return Response::redirect($back($r) . 'ok=activitygone');
        };

        foreach (['crm', 'crm-organizations'] as $page) {
            $context->adminPages()->action($page, 'activity-add', $addActivity);
            $context->adminPages()->action($page, 'activity-delete', $deleteActivity);
        }

        // Teach an MCP agent how to drive the CRM (ADR 0013).
        $context->skills()->register('CRM', Guide::text());
    }
}
