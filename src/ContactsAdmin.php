<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * The Contacts admin page. A search + list and a create/edit form for the people
 * the CRM tracks. Every author value is escaped on the way out ({@see e}); the
 * store keeps it raw. Styling lives in one nonce-carrying `<style>` block because
 * the admin CSP is nonce-only for `style-src`. The page + its POST actions are
 * gated on `nimbuscms.crm:write` and CSRF-protected by core (ADR 0020).
 */
final class ContactsAdmin
{
    private const NOTICES = [
        'saved'       => ['ok', 'Contact saved.'],
        'deleted'     => ['ok', 'Contact deleted.'],
        'activity'    => ['ok', 'Activity logged.'],
        'activitygone' => ['ok', 'Activity deleted.'],
        'bademail'    => ['err', 'That email address is not valid.'],
        'noname'      => ['err', 'A contact needs a first or last name.'],
        'activitybad' => ['err', 'Could not log that activity — check the details.'],
        'invalid'     => ['err', 'Check the details and try again.'],
    ];

    public function __construct(
        private Contacts $contacts,
        private Organizations $organizations,
        private Activities $activities,
    ) {
    }

    /**
     * @param string  $csrf   CSRF token for the forms
     * @param ?string $notice a fixed notice code from the ?ok=/?err= redirect
     * @param ?string $edit   a contact id to load into the form (from ?edit=)
     * @param ?string $q      a search term (from ?q=)
     * @param string  $nonce  the request CSP nonce
     */
    public function render(string $csrf = '', ?string $notice = null, ?string $edit = null, ?string $q = null, string $nonce = ''): string
    {
        $editId      = ($edit !== null && preg_match('/^\d+$/', trim($edit)) === 1) ? (int) trim($edit) : null;
        $editContact = $editId !== null ? $this->contacts->get($editId) : null;
        $q           = $q !== null ? trim($q) : '';
        $contacts    = $this->contacts->all($q === '' ? null : $q);

        $html = $this->styles($nonce)
            . '<div class="nb-page-head"><h1>Contacts</h1></div>'
            . $this->notice($notice)
            . '<p class="nb-muted cr-intro">The people your business keeps track of. This is private data — only roles with the <code>crm</code> capability can see it.</p>';

        $html .= $this->form($csrf, $editContact);
        if ($editContact !== null) {
            $html .= ActivitiesAdmin::render($csrf, 'crm', Activities::SUBJECT_CONTACT, (int) $editContact['id'], $this->activities->forSubject(Activities::SUBJECT_CONTACT, (int) $editContact['id']), $nonce);
        }
        $html .= $this->list($csrf, $contacts, $q, $editId);

        return $html;
    }

    /** @param array<string,mixed>|null $edit */
    private function form(string $csrf, ?array $edit): string
    {
        $val = static fn (string $k): string => $edit !== null && $edit[$k] !== null ? self::e((string) $edit[$k]) : '';
        $idField = $edit !== null ? '<input type="hidden" name="id" value="' . self::e((string) $edit['id']) . '">' : '';
        $currentOrg = $edit !== null && $edit['org_id'] !== null ? (int) $edit['org_id'] : null;

        return '<h2>' . ($edit !== null ? 'Edit contact' : 'Add a contact') . '</h2>'
            . '<form method="post" action="/admin/crm/contact-save" class="cr-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">' . $idField
            . '<div class="cr-row">'
            . '<label>First name<input type="text" name="first_name" value="' . $val('first_name') . '" maxlength="120"></label>'
            . '<label>Last name<input type="text" name="last_name" value="' . $val('last_name') . '" maxlength="120"></label>'
            . '</div>'
            . '<div class="cr-row">'
            . '<label>Email<input type="email" name="email" value="' . $val('email') . '" maxlength="191"></label>'
            . '<label>Phone<input type="text" name="phone" value="' . $val('phone') . '" maxlength="60"></label>'
            . '</div>'
            . '<label>Organization' . $this->orgSelect($currentOrg) . '</label>'
            . '<label>Notes<textarea name="notes" rows="3" maxlength="10000">' . $val('notes') . '</textarea></label>'
            . '<div class="cr-actions"><button type="submit" class="nb-btn">' . ($edit !== null ? 'Save contact' : 'Add contact') . '</button>'
            . ($edit !== null ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm">Cancel</a>' : '')
            . '</div></form>';
    }

    private function orgSelect(?int $current): string
    {
        $options = '<option value="">— none —</option>';
        foreach ($this->organizations->all() as $o) {
            $sel = $current === (int) $o['id'] ? ' selected' : '';
            $options .= '<option value="' . self::e((string) $o['id']) . '"' . $sel . '>' . self::e((string) $o['name']) . '</option>';
        }
        return '<select name="org_id">' . $options . '</select>';
    }

    /**
     * @param list<array<string,mixed>> $contacts
     */
    private function list(string $csrf, array $contacts, string $q, ?int $editId): string
    {
        $html = '<h2>All contacts</h2>'
            . '<form method="get" action="/admin/crm" class="cr-search" role="search">'
            . '<input type="search" name="q" value="' . self::e($q) . '" placeholder="Search name or email">'
            . '<button type="submit" class="nb-btn nb-btn-quiet">Search</button>'
            . ($q !== '' ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm">Clear</a>' : '')
            . '</form>';

        if ($contacts === []) {
            return $html . '<p class="nb-muted">' . ($q !== '' ? 'No contacts match “' . self::e($q) . '”.' : 'No contacts yet — add one above.') . '</p>';
        }

        $rows = '';
        foreach ($contacts as $c) {
            $name = trim(((string) $c['first_name']) . ' ' . ((string) $c['last_name']));
            $rows .= '<tr>'
                . '<td data-label="Name"><a href="/admin/crm?edit=' . self::e((string) $c['id']) . '">' . self::e($name === '' ? '(no name)' : $name) . '</a></td>'
                . '<td data-label="Organization">' . self::e((string) ($c['organization'] ?? '')) . '</td>'
                . '<td data-label="Email">' . self::e((string) ($c['email'] ?? '')) . '</td>'
                . '<td data-label="Phone">' . self::e((string) ($c['phone'] ?? '')) . '</td>'
                . '<td data-label="" class="cr-rowact">'
                . '<form method="post" action="/admin/crm/contact-delete" data-confirm="Delete this contact? This cannot be undone.">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . self::e((string) $c['id']) . '">'
                . '<button type="submit" class="cr-link-danger">Delete</button>'
                . '</form></td>'
                . '</tr>';
        }

        return $html
            . '<table class="cr-table"><thead><tr><th>Name</th><th>Organization</th><th>Email</th><th>Phone</th><th></th></tr></thead><tbody>'
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
            . '.cr-row{display:flex;gap:.75rem;flex-wrap:wrap}'
            . '.cr-form label{display:flex;flex-direction:column;gap:.25rem;flex:1 1 14rem;font-weight:600;font-size:.85rem}'
            . '.cr-form input,.cr-form textarea,.cr-form select{font:inherit;padding:.5rem .6rem;min-height:44px;box-sizing:border-box}'
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

    /** Escape a value for HTML output (the admin CSP is nonce-only; every value is escaped). */
    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
