<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * The Deals admin — a pipeline **board** (a column per stage, open deals bucketed
 * into it, with a per-column count and value total), a create/edit form, and the
 * inline activity timeline on an open deal. Same discipline as the other CRM pages:
 * every author value is escaped on output ({@see e}), styling is one nonce-carrying
 * `<style>` block (the admin CSP is nonce-only for `style-src`), and the page + its
 * POST actions are gated on `nimbuscms.crm:write` + CSRF by core (ADR 0020). The
 * board reflows to a single column on a phone.
 */
final class DealsAdmin
{
    private const NOTICES = [
        'saved'        => ['ok', 'Deal saved.'],
        'deleted'      => ['ok', 'Deal deleted.'],
        'activity'     => ['ok', 'Activity logged.'],
        'activitygone' => ['ok', 'Activity deleted.'],
        'tagged'       => ['ok', 'Tag added.'],
        'untagged'     => ['ok', 'Tag removed.'],
        'notitle'      => ['err', 'A deal needs a title.'],
        'activitybad'  => ['err', 'Could not log that activity — check the details.'],
        'tagbad'       => ['err', 'Could not add that tag — check the details.'],
        'invalid'      => ['err', 'Check the details and try again.'],
    ];

    private const STAGE_LABELS = [
        'lead'        => 'Lead',
        'qualified'   => 'Qualified',
        'proposal'    => 'Proposal',
        'negotiation' => 'Negotiation',
    ];

    public function __construct(
        private Deals $deals,
        private Contacts $contacts,
        private Organizations $organizations,
        private Activities $activities,
        private Tags $tags,
    ) {
    }

    public function render(string $csrf = '', ?string $notice = null, ?string $edit = null, ?string $q = null, string $nonce = '', ?string $tag = null): string
    {
        $editId   = ($edit !== null && preg_match('/^\d+$/', trim($edit)) === 1) ? (int) trim($edit) : null;
        $editDeal = $editId !== null ? $this->deals->get($editId) : null;
        $q        = $q !== null ? trim($q) : '';
        $tagId    = ($tag !== null && preg_match('/^\d+$/', trim($tag)) === 1) ? (int) trim($tag) : null;
        // When a tag filter is active, the id set restricts the board and results.
        $only = $tagId !== null ? $this->tags->idsFor(Activities::SUBJECT_DEAL, $tagId) : null;

        $html = $this->styles($nonce)
            . '<div class="nb-page-head"><h1>Deals</h1></div>'
            . $this->notice($notice)
            . '<p class="nb-muted cr-intro">Your pipeline of opportunities. Move a deal along by changing its stage; mark it won or lost when it closes.</p>'
            . $this->form($csrf, $editDeal);

        if ($editDeal !== null) {
            $html .= ActivitiesAdmin::render($csrf, 'crm-deals', Activities::SUBJECT_DEAL, (int) $editDeal['id'], $this->activities->forSubject(Activities::SUBJECT_DEAL, (int) $editDeal['id']), $nonce);
            $html .= TagsAdmin::block($csrf, 'crm-deals', Activities::SUBJECT_DEAL, (int) $editDeal['id'], $this->tags->tagsFor(Activities::SUBJECT_DEAL, (int) $editDeal['id']), $this->tags->allTags(), $nonce);
        }

        $html .= $this->search($q);
        $html .= TagsAdmin::filterBar('crm-deals', $this->tags->allTags(), $tagId);
        $html .= $q !== '' ? $this->results($csrf, $q, $only) : $this->board($csrf, $only);

        return $html;
    }

    /**
     * Keep only rows whose id is in `$only`; a null filter keeps everything.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<int>|null            $only
     * @return list<array<string,mixed>>
     */
    private function restrict(array $rows, ?array $only): array
    {
        if ($only === null) {
            return $rows;
        }
        return array_values(array_filter($rows, static fn (array $d): bool => in_array((int) $d['id'], $only, true)));
    }

    /** @param array<string,mixed>|null $edit */
    private function form(string $csrf, ?array $edit): string
    {
        $val = static fn (string $k): string => $edit !== null && $edit[$k] !== null ? self::e((string) $edit[$k]) : '';
        $idField = $edit !== null ? '<input type="hidden" name="id" value="' . self::e((string) $edit['id']) . '">' : '';

        return '<h2>' . ($edit !== null ? 'Edit deal' : 'Add a deal') . '</h2>'
            . '<form method="post" action="/admin/crm-deals/deal-save" class="cr-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">' . $idField
            . '<label>Title<input type="text" name="title" value="' . $val('title') . '" maxlength="200" required></label>'
            . '<div class="cr-row">'
            . '<label>Value<input type="text" name="value" value="' . $val('value') . '" inputmode="decimal" placeholder="0.00"></label>'
            . '<label>Currency<input type="text" name="currency" value="' . ($edit !== null ? $val('currency') : 'USD') . '" maxlength="3" placeholder="USD"></label>'
            . '</div>'
            . '<div class="cr-row">'
            . '<label>Stage' . $this->enumSelect('stage', Deals::STAGES, $edit !== null ? (string) $edit['stage'] : 'lead') . '</label>'
            . '<label>Status' . $this->enumSelect('status', Deals::STATUSES, $edit !== null ? (string) $edit['status'] : 'open') . '</label>'
            . '</div>'
            . '<div class="cr-row">'
            . '<label>Contact' . $this->contactSelect($edit !== null && $edit['contact_id'] !== null ? (int) $edit['contact_id'] : null) . '</label>'
            . '<label>Organization' . $this->orgSelect($edit !== null && $edit['org_id'] !== null ? (int) $edit['org_id'] : null) . '</label>'
            . '</div>'
            . '<div class="cr-actions"><button type="submit" class="nb-btn">' . ($edit !== null ? 'Save deal' : 'Add deal') . '</button>'
            . ($edit !== null ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm-deals">Cancel</a>' : '')
            . '</div></form>';
    }

    /** @param list<string> $allowed */
    private function enumSelect(string $name, array $allowed, string $current): string
    {
        $options = '';
        foreach ($allowed as $v) {
            $label = self::STAGE_LABELS[$v] ?? ucfirst($v);
            $options .= '<option value="' . self::e($v) . '"' . ($v === $current ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return '<select name="' . self::e($name) . '">' . $options . '</select>';
    }

    private function contactSelect(?int $current): string
    {
        $options = '<option value="">— none —</option>';
        foreach ($this->contacts->all() as $c) {
            $name = trim(((string) $c['first_name']) . ' ' . ((string) $c['last_name']));
            $options .= '<option value="' . self::e((string) $c['id']) . '"' . ($current === (int) $c['id'] ? ' selected' : '') . '>' . self::e($name === '' ? '(no name)' : $name) . '</option>';
        }
        return '<select name="contact_id">' . $options . '</select>';
    }

    private function orgSelect(?int $current): string
    {
        $options = '<option value="">— none —</option>';
        foreach ($this->organizations->all() as $o) {
            $options .= '<option value="' . self::e((string) $o['id']) . '"' . ($current === (int) $o['id'] ? ' selected' : '') . '>' . self::e((string) $o['name']) . '</option>';
        }
        return '<select name="org_id">' . $options . '</select>';
    }

    private function search(string $q): string
    {
        return '<form method="get" action="/admin/crm-deals" class="cr-search" role="search">'
            . '<input type="search" name="q" value="' . self::e($q) . '" placeholder="Search deals by title">'
            . '<button type="submit" class="nb-btn nb-btn-quiet">Search</button>'
            . ($q !== '' ? ' <a class="nb-btn nb-btn-quiet" href="/admin/crm-deals">Back to board</a>' : '')
            . '</form>';
    }

    /** @param list<int>|null $only */
    private function results(string $csrf, string $q, ?array $only): string
    {
        $deals = $this->restrict($this->deals->all($q), $only);
        if ($deals === []) {
            return '<p class="nb-muted">No deals match “' . self::e($q) . '”.</p>';
        }
        $cards = '';
        foreach ($deals as $d) {
            $cards .= $this->card($csrf, $d, true);
        }
        return '<ul class="cr-deal-flat">' . $cards . '</ul>';
    }

    /** @param list<int>|null $only */
    private function board(string $csrf, ?array $only): string
    {
        $open = $this->restrict($this->deals->all(null, 'open'), $only);

        $byStage = [];
        foreach (Deals::STAGES as $s) {
            $byStage[$s] = [];
        }
        foreach ($open as $d) {
            $byStage[(string) $d['stage']][] = $d;
        }

        $cols = '';
        foreach (Deals::STAGES as $stage) {
            $deals = $byStage[$stage];
            $sum   = 0.0;
            foreach ($deals as $d) {
                $sum += $d['value'] !== null ? (float) $d['value'] : 0.0;
            }
            $cards = '';
            foreach ($deals as $d) {
                $cards .= $this->card($csrf, $d, false);
            }
            if ($cards === '') {
                $cards = '<p class="nb-muted cr-col-empty">No deals.</p>';
            }
            $total = $sum > 0 ? '<span class="cr-col-sum">' . self::e(number_format($sum, 2, '.', ',')) . '</span>' : '';
            $cols .= '<section class="cr-col">'
                . '<h3>' . self::e(self::STAGE_LABELS[$stage]) . ' <span class="cr-col-count">' . count($deals) . '</span>' . $total . '</h3>'
                . '<div class="cr-col-body">' . $cards . '</div>'
                . '</section>';
        }

        return '<div class="cr-board">' . $cols . '</div>' . $this->closed($csrf, $only);
    }

    /** @param list<int>|null $only */
    private function closed(string $csrf, ?array $only): string
    {
        $won  = $this->restrict($this->deals->all(null, 'won'), $only);
        $lost = $this->restrict($this->deals->all(null, 'lost'), $only);
        if ($won === [] && $lost === []) {
            return '';
        }
        $cards = '';
        foreach ([...$won, ...$lost] as $d) {
            $cards .= $this->card($csrf, $d, true);
        }
        return '<h2 class="cr-closed-head">Closed</h2><ul class="cr-deal-flat">' . $cards . '</ul>';
    }

    /**
     * One deal card. `$showStatus` adds a status badge (used off-board, where the
     * column no longer implies the stage/status).
     *
     * @param array<string,mixed> $d
     */
    private function card(string $csrf, array $d, bool $showStatus): string
    {
        $money = '';
        if ($d['value'] !== null) {
            $money = '<span class="cr-deal-value">' . self::e((string) $d['currency']) . ' ' . self::e(number_format((float) $d['value'], 2, '.', ',')) . '</span>';
        }
        $who = [];
        if (($d['contact'] ?? null) !== null && (string) $d['contact'] !== '') {
            $who[] = self::e((string) $d['contact']);
        }
        if (($d['organization'] ?? null) !== null && (string) $d['organization'] !== '') {
            $who[] = self::e((string) $d['organization']);
        }
        $badge = $showStatus ? '<span class="cr-badge cr-badge-' . self::e((string) $d['status']) . '">' . self::e(ucfirst((string) $d['status'])) . '</span>' : '';

        return '<li class="cr-deal">'
            . '<div class="cr-deal-top"><a class="cr-deal-title" href="/admin/crm-deals?edit=' . self::e((string) $d['id']) . '">' . self::e((string) $d['title']) . '</a>' . $badge . '</div>'
            . ($money !== '' ? '<div>' . $money . '</div>' : '')
            . ($who !== [] ? '<div class="cr-deal-who">' . implode(' · ', $who) . '</div>' : '')
            . '<form method="post" action="/admin/crm-deals/deal-delete" class="cr-deal-del" data-confirm="Delete this deal? This cannot be undone.">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="id" value="' . self::e((string) $d['id']) . '">'
            . '<button type="submit" class="cr-link-danger">Delete</button></form>'
            . '</li>';
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
            . '.cr-form label{display:flex;flex-direction:column;gap:.25rem;flex:1 1 12rem;font-weight:600;font-size:.85rem}'
            . '.cr-form input,.cr-form select{font:inherit;padding:.5rem .6rem;min-height:44px;box-sizing:border-box}'
            . '.cr-search{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:0 0 1rem}'
            . '.cr-search input{font:inherit;padding:.5rem .6rem;min-height:44px;flex:1 1 16rem}'
            . '.cr-board{display:flex;gap:1rem;align-items:flex-start;overflow-x:auto;padding-bottom:.5rem}'
            . '.cr-col{flex:1 1 0;min-width:15rem;background:rgba(128,128,128,.06);border-radius:10px;padding:.6rem .6rem 1rem}'
            . '.cr-col h3{display:flex;align-items:center;gap:.4rem;font-size:.9rem;margin:.2rem .2rem .6rem}'
            . '.cr-col-count{background:rgba(128,128,128,.2);border-radius:999px;padding:.05rem .5rem;font-size:.75rem}'
            . '.cr-col-sum{margin-left:auto;font-variant-numeric:tabular-nums;font-size:.8rem;opacity:.75}'
            . '.cr-col-empty{margin:.3rem .2rem}'
            . '.cr-col-body,.cr-deal-flat{display:flex;flex-direction:column;gap:.5rem}'
            . '.cr-deal-flat{list-style:none;margin:.5rem 0 0;padding:0}'
            . '.cr-deal{list-style:none;background:var(--nb-surface,#fff);border:1px solid rgba(128,128,128,.2);border-radius:8px;padding:.55rem .65rem}'
            . '.cr-deal-top{display:flex;justify-content:space-between;align-items:center;gap:.4rem}'
            . '.cr-deal-title{font-weight:700;text-decoration:none}'
            . '.cr-deal-value{font-variant-numeric:tabular-nums;font-size:.85rem}'
            . '.cr-deal-who{font-size:.8rem;opacity:.75;margin-top:.15rem}'
            . '.cr-deal-del{margin-top:.35rem}'
            . '.cr-badge{font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:999px;text-transform:uppercase;letter-spacing:.03em}'
            . '.cr-badge-open{background:rgba(52,152,219,.18);color:#2471a3}'
            . '.cr-badge-won{background:rgba(39,174,96,.18);color:#1e8449}'
            . '.cr-badge-lost{background:rgba(192,57,43,.15);color:#c0392b}'
            . '.cr-closed-head{margin-top:2rem}'
            . '.cr-link-danger{background:none;border:0;color:#c0392b;font:inherit;cursor:pointer;text-decoration:underline;padding:0;min-height:44px}'
            . '@media (max-width:52rem){.cr-board{flex-direction:column}.cr-col{width:100%;min-width:0}}'
            . '</style>';
    }

    /** Escape a value for HTML output (the admin CSP is nonce-only; every value is escaped). */
    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
