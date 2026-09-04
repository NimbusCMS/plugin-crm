<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * The Organizations admin page — a search + list and a create/edit form for the
 * companies the CRM tracks. Same discipline as {@see ContactsAdmin}: every author
 * value is escaped on output, styling lives in one nonce-carrying `<style>` block,
 * and the page + its POST actions are gated on `nimbuscms.crm:write` + CSRF.
 */
final class OrganizationsAdmin
{
    private const NOTICES = [
        'saved'        => ['ok', 'Organization saved.'],
        'deleted'      => ['ok', 'Organization deleted — its contacts were kept and unlinked.'],
        'activity'     => ['ok', 'Activity logged.'],
        'activitygone' => ['ok', 'Activity deleted.'],
        'tagged'       => ['ok', 'Tag added.'],
        'untagged'     => ['ok', 'Tag removed.'],
        'noname'       => ['err', 'An organization needs a name.'],
        'activitybad'  => ['err', 'Could not log that activity — check the details.'],
        'tagbad'       => ['err', 'Could not add that tag — check the details.'],
        'invalid'      => ['err', 'Check the details and try again.'],
    ];

    public function __construct(
        private Organizations $organizations,
        private Activities $activities,
        private Tags $tags,
    ) {
    }

    /**
     * @param string  $csrf  CSRF token for the forms
     * @param ?string $notice a fixed notice code from the ?ok=/?err= redirect
     * @param ?string $edit  an organization id to load into the form (from ?edit=)
     * @param ?string $q     a search term (from ?q=)
     * @param string  $nonce the request CSP nonce
     * @param ?string $tag   a tag id to filter the list by (from ?tag=)
     */
    public function render(string $csrf = '', ?string $notice = null, ?string $edit = null, ?string $q = null, string $nonce = '', ?string $tag = null): string
    {
        $editId  = ($edit !== null && preg_match('/^\d+$/', trim($edit)) === 1) ? (int) trim($edit) : null;
        $editOrg = $editId !== null ? $this->organizations->get($editId) : null;
        $q       = $q !== null ? trim($q) : '';
        $tagId   = ($tag !== null && preg_match('/^\d+$/', trim($tag)) === 1) ? (int) trim($tag) : null;
        $orgs    = $this->organizations->all($q === '' ? null : $q);
        if ($tagId !== null) {
            $ids  = $this->tags->idsFor(Activities::SUBJECT_ORGANIZATION, $tagId);
            $orgs = array_values(array_filter($orgs, static fn (array $o): bool => in_array((int) $o['id'], $ids, true)));
        }

        return $this->styles($nonce)
            . '<div class="nb-page-head"><h1>Organizations</h1></div>'
            . $this->notice($notice)
            . '<p class="nb-muted cr-intro">The companies your contacts belong to. Deleting one keeps its people — they are simply unlinked.</p>'
            . $this->form($csrf, $editOrg)
            . ($editOrg !== null ? ActivitiesAdmin::render($csrf, 'crm-organizations', Activities::SUBJECT_ORGANIZATION, (int) $editOrg['id'], $this->activities->forSubject(Activities::SUBJECT_ORGANIZATION, (int) $editOrg['id']), $nonce) : '')
            . ($editOrg !== null ? TagsAdmin::block($csrf, 'crm-organizations', Activities::SUBJECT_ORGANIZATION, (int) $editOrg['id'], $this->tags->tagsFor(Activities::SUBJECT_ORGANIZATION, (int) $editOrg['id']), $this->tags->allTags(), $nonce) : '')
            . TagsAdmin::filterBar('crm-organizations', $this->tags->allTags(), $tagId)
            . $this->list($csrf, $orgs, $q);
    }

    /** @param array<string,mixed>|null $edit */
    private function form(string $csrf, ?array $edit): string
    {
        $val = static fn (string $k): string => $edit !== null && $edit[$k] !== null ? self::e((string) $edit[$k]) : '';
        $idField = $edit !== null ? '<input type="hidden" name="id" value="' . self::e((string) $edit['id']) . '">' : '';

        return '<h2>' . ($edit !== null ? 'Edit organization' : 'Add an organization') . '</h2>'
            . '<form method="post" action="/admin/crm-organizations/org-save" class="cr-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">' . $idField
            . '<label>Name<input type="text" name="name" value="' . $val('name') . '" maxlength="200" required></label>'
            . '<label>Website<input type="text" name="website" value="' . $val('website') . '" maxlength="255"></label>'
            . '<label>Notes<textarea name="notes" rows="3" maxlength="10000">' . $val('notes') . '</textarea></label>'
            . '<div class="cr-actions"><button type="submit" class="nb-btn">' . ($edit !== null ? 'Save organization' : 'Add organization') . '</button>'
            . ($edit !== null ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm-organizations">Cancel</a>' : '')
            . '</div></form>';
    }

    /**
     * @param list<array<string,mixed>> $orgs
     */
    private function list(string $csrf, array $orgs, string $q): string
    {
        $html = '<h2>All organizations</h2>'
            . '<form method="get" action="/admin/crm-organizations" class="cr-search" role="search">'
            . '<input type="search" name="q" value="' . self::e($q) . '" placeholder="Search by name">'
            . '<button type="submit" class="nb-btn nb-btn-quiet">Search</button>'
            . ($q !== '' ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm-organizations">Clear</a>' : '')
            . '</form>';

        if ($orgs === []) {
            return $html . '<p class="nb-muted">' . ($q !== '' ? 'No organizations match “' . self::e($q) . '”.' : 'No organizations yet — add one above.') . '</p>';
        }

        $rows = '';
        foreach ($orgs as $o) {
            $rows .= '<tr>'
                . '<td data-label="Name"><a href="/admin/crm-organizations?edit=' . self::e((string) $o['id']) . '">' . self::e((string) $o['name']) . '</a></td>'
                . '<td data-label="Website">' . self::e((string) ($o['website'] ?? '')) . '</td>'
                . '<td data-label="" class="cr-rowact">'
                . '<form method="post" action="/admin/crm-organizations/org-delete" data-confirm="Delete this organization? Its contacts are kept and unlinked.">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . self::e((string) $o['id']) . '">'
                . '<button type="submit" class="cr-link-danger">Delete</button>'
                . '</form></td>'
                . '</tr>';
        }

        return $html
            . '<table class="cr-table"><thead><tr><th>Name</th><th>Website</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    private function notice(?string $code): string
    {
        if ($code === null || !isset(self::NOTICES[$code])) {
            return '';
        }
        [$kind, $message] = self::NOTICES[$code];
        return '<div class="nb-notice nb-notice-' . ($kind === 'ok' ? 'ok' : 'err') . '">' . self::e($message) . '</div>';
    }

    private function styles(string $nonce): string
    {
        return '<style nonce="' . self::e($nonce) . '">'
            . '.cr-intro{max-width:60ch}'
            . '.cr-form{max-width:44rem;display:flex;flex-direction:column;gap:.75rem;margin:0 0 2rem}'
            . '.cr-form label{display:flex;flex-direction:column;gap:.25rem;font-weight:600;font-size:.85rem}'
            . '.cr-form input,.cr-form textarea{font:inherit;padding:.5rem .6rem;min-height:44px;box-sizing:border-box}'
            . '.cr-form textarea{min-height:5rem}'
            . '.cr-search{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:0 0 1rem}'
            . '.cr-search input{font:inherit;padding:.5rem .6rem;min-height:44px;flex:1 1 16rem}'
            . '.cr-table{width:100%;border-collapse:collapse}'
            . '.cr-table th,.cr-table td{text-align:left;padding:.6rem .5rem;border-bottom:1px solid rgba(128,128,128,.2)}'
            . '.cr-rowact{text-align:right}'
            . '.cr-link-danger{background:none;border:0;color:#c0392b;font:inherit;cursor:pointer;text-decoration:underline;padding:0;min-height:44px}'
            . '@media (max-width:40rem){'
            . '.cr-table,.cr-table tbody,.cr-table tr,.cr-table td{display:block}'
            . '.cr-table thead{display:none}'
            . '.cr-table tr{border:1px solid rgba(128,128,128,.25);border-radius:8px;margin:0 0 .75rem;padding:.35rem .6rem}'
            . '.cr-table td{border:0;padding:.3rem 0}'
            . '.cr-table td[data-label]:before{content:attr(data-label) " ";font-weight:700;font-size:.8rem}'
            . '.cr-rowact{text-align:left}'
            . '}'
            . '</style>';
    }

    /** Escape a value for HTML output. */
    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
