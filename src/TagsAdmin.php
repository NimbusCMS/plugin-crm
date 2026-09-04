<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * Tags in the admin, three ways:
 *
 *  - the **management page** ({@see render}) — list every tag with its usage count,
 *    create, rename and delete;
 *  - the **record block** ({@see block}) — the tags on one contact/organization/deal,
 *    each removable, plus an "add a tag" control (pick an existing one or type a new
 *    name), embedded on that record's edit page;
 *  - the **filter bar** ({@see filterBar}) — the tag chips on a list page that filter
 *    it to "everything tagged X".
 *
 * Every author value (a tag name) is escaped on output; the page + its POST actions
 * are gated on `nimbuscms.crm:write` + CSRF by core (ADR 0020). Styling is one
 * nonce-carrying `<style>` block.
 */
final class TagsAdmin
{
    private const NOTICES = [
        'saved'   => ['ok', 'Tag saved.'],
        'deleted' => ['ok', 'Tag deleted.'],
        'noname'  => ['err', 'A tag needs a name.'],
        'dupe'    => ['err', 'A tag with that name already exists.'],
        'invalid' => ['err', 'Check the details and try again.'],
    ];

    public function __construct(private Tags $tags)
    {
    }

    public function render(string $csrf = '', ?string $notice = null, ?string $edit = null, string $nonce = ''): string
    {
        $editId  = ($edit !== null && preg_match('/^\d+$/', trim($edit)) === 1) ? (int) trim($edit) : null;
        $editTag = $editId !== null ? $this->tags->getTag($editId) : null;
        $tags    = $this->tags->allTags();

        return $this->styles($nonce)
            . '<div class="nb-page-head"><h1>Tags</h1></div>'
            . $this->notice($notice)
            . '<p class="nb-muted cr-intro">Labels you can apply to contacts, organizations and deals — then filter by. Deleting a tag removes it from everything; the records are kept.</p>'
            . $this->form($csrf, $editTag)
            . $this->list($csrf, $tags);
    }

    /** @param array<string,mixed>|null $edit */
    private function form(string $csrf, ?array $edit): string
    {
        $val     = $edit !== null ? self::e((string) $edit['name']) : '';
        $idField = $edit !== null ? '<input type="hidden" name="id" value="' . self::e((string) $edit['id']) . '">' : '';

        return '<h2>' . ($edit !== null ? 'Rename tag' : 'Add a tag') . '</h2>'
            . '<form method="post" action="/admin/crm-tags/tag-save" class="cr-form cr-form-inline">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">' . $idField
            . '<label>Name<input type="text" name="name" value="' . $val . '" maxlength="60" required></label>'
            . '<div class="cr-actions"><button type="submit" class="nb-btn">' . ($edit !== null ? 'Save' : 'Add tag') . '</button>'
            . ($edit !== null ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm-tags">Cancel</a>' : '')
            . '</div></form>';
    }

    /** @param list<array{id:int,name:string,count:int}> $tags */
    private function list(string $csrf, array $tags): string
    {
        if ($tags === []) {
            return '<p class="nb-muted">No tags yet — add one above.</p>';
        }
        $rows = '';
        foreach ($tags as $t) {
            $rows .= '<tr>'
                . '<td data-label="Tag"><a href="/admin/crm-tags?edit=' . self::e((string) $t['id']) . '">' . self::e($t['name']) . '</a></td>'
                . '<td data-label="Used">' . self::e((string) $t['count']) . '</td>'
                . '<td data-label="" class="cr-rowact">'
                . '<form method="post" action="/admin/crm-tags/tag-delete" data-confirm="Delete this tag? It will be removed from everything it is on.">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . self::e((string) $t['id']) . '">'
                . '<button type="submit" class="cr-link-danger">Delete</button></form></td>'
                . '</tr>';
        }
        return '<table class="cr-table"><thead><tr><th>Tag</th><th>Used</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * The tag block for a record's edit page.
     *
     * @param list<array{id:int,name:string}>       $on  tags already on the subject
     * @param list<array{id:int,name:string,count:int}> $all every tag (to offer)
     */
    public static function block(string $csrf, string $page, string $subjectType, int $subjectId, array $on, array $all, string $nonce): string
    {
        $chips = '';
        foreach ($on as $t) {
            $chips .= '<span class="cr-tag-chip">' . self::e($t['name'])
                . '<form method="post" action="/admin/' . self::e($page) . '/tag-detach" class="cr-tag-x">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="subject_type" value="' . self::e($subjectType) . '">'
                . '<input type="hidden" name="subject_id" value="' . self::e((string) $subjectId) . '">'
                . '<input type="hidden" name="tag_id" value="' . self::e((string) $t['id']) . '">'
                . '<button type="submit" aria-label="Remove tag">×</button></form></span>';
        }
        if ($chips === '') {
            $chips = '<span class="nb-muted">No tags yet.</span>';
        }

        $onIds   = array_column($on, 'id');
        $options = '<option value="">— pick a tag —</option>';
        foreach ($all as $t) {
            if (in_array($t['id'], $onIds, true)) {
                continue;
            }
            $options .= '<option value="' . self::e((string) $t['id']) . '">' . self::e($t['name']) . '</option>';
        }

        return self::blockStyles($nonce)
            . '<section class="cr-tags"><h2>Tags</h2>'
            . '<div class="cr-tag-chips">' . $chips . '</div>'
            . '<form method="post" action="/admin/' . self::e($page) . '/tag-attach" class="cr-tag-add">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="subject_type" value="' . self::e($subjectType) . '">'
            . '<input type="hidden" name="subject_id" value="' . self::e((string) $subjectId) . '">'
            . '<select name="tag_id">' . $options . '</select>'
            . '<input type="text" name="tag_name" maxlength="60" placeholder="or a new tag">'
            . '<button type="submit" class="nb-btn nb-btn-quiet">Add tag</button>'
            . '</form></section>';
    }

    /**
     * The filter chips for a list page. `$activeId` is the tag currently filtered on.
     *
     * @param list<array{id:int,name:string,count:int}> $all
     */
    public static function filterBar(string $page, array $all, ?int $activeId): string
    {
        if ($all === []) {
            return '';
        }
        $chips = '';
        foreach ($all as $t) {
            if ($t['count'] === 0) {
                continue;
            }
            $active = $activeId === $t['id'];
            $chips .= '<a class="cr-filter-chip' . ($active ? ' is-active' : '') . '" href="/admin/' . self::e($page) . ($active ? '' : '?tag=' . self::e((string) $t['id'])) . '">'
                . self::e($t['name']) . ' <span class="cr-filter-n">' . self::e((string) $t['count']) . '</span></a>';
        }
        if ($chips === '') {
            return '';
        }
        return '<div class="cr-filter" role="group" aria-label="Filter by tag"><span class="cr-filter-label">Tags:</span>' . $chips
            . ($activeId !== null ? '<a class="cr-filter-clear" href="/admin/' . self::e($page) . '">Clear</a>' : '')
            . '</div>';
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
            . '.cr-form-inline{flex-flow:row wrap;align-items:flex-end}'
            . '.cr-form label{display:flex;flex-direction:column;gap:.25rem;flex:1 1 14rem;font-weight:600;font-size:.85rem}'
            . '.cr-form input{font:inherit;padding:.5rem .6rem;min-height:44px;box-sizing:border-box}'
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

    private static function blockStyles(string $nonce): string
    {
        return '<style nonce="' . self::e($nonce) . '">'
            . '.cr-tags{max-width:44rem;margin:1.5rem 0 0;border-top:1px solid rgba(128,128,128,.2);padding-top:1rem}'
            . '.cr-tag-chips{display:flex;flex-wrap:wrap;gap:.4rem;margin:0 0 .75rem}'
            . '.cr-tag-chip{display:inline-flex;align-items:center;gap:.35rem;background:rgba(128,128,128,.14);border-radius:999px;padding:.2rem .3rem .2rem .7rem;font-size:.85rem}'
            . '.cr-tag-x{display:inline}'
            . '.cr-tag-x button{background:none;border:0;cursor:pointer;font:inherit;line-height:1;padding:.1rem .35rem;min-height:32px;border-radius:999px;color:inherit;opacity:.7}'
            . '.cr-tag-x button:hover{opacity:1;background:rgba(128,128,128,.2)}'
            . '.cr-tag-add{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}'
            . '.cr-tag-add select,.cr-tag-add input{font:inherit;padding:.5rem .6rem;min-height:44px;box-sizing:border-box}'
            . '.cr-filter{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;margin:0 0 1rem}'
            . '.cr-filter-label{font-size:.8rem;font-weight:700;opacity:.7}'
            . '.cr-filter-chip{display:inline-flex;align-items:center;gap:.35rem;text-decoration:none;background:rgba(128,128,128,.12);border-radius:999px;padding:.25rem .7rem;font-size:.82rem;color:inherit}'
            . '.cr-filter-chip.is-active{background:rgba(52,152,219,.22);color:#2471a3;font-weight:700}'
            . '.cr-filter-n{opacity:.6;font-size:.75rem}'
            . '.cr-filter-clear{font-size:.8rem;text-decoration:underline}'
            . '</style>';
    }

    /** Escape a value for HTML output (the admin CSP is nonce-only; every value is escaped). */
    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
