<?php

declare(strict_types=1);

namespace NimbusCMS\Crm;

/**
 * The activity timeline block, embedded on a record's edit page ({@see ContactsAdmin},
 * {@see OrganizationsAdmin}). It renders the subject's timeline, an "add" form, and a
 * delete control per entry — all posting to the host page's `activity-add` /
 * `activity-delete` actions (crm:write + CSRF gated by core). Every author value —
 * the note body and the `author` name — is escaped on the way out; styling is one
 * nonce-carrying `<style>` block, since the admin CSP is nonce-only for `style-src`.
 */
final class ActivitiesAdmin
{
    /**
     * @param string                    $csrf       CSRF token for the forms
     * @param string                    $page       host admin slug (`crm` or `crm-organizations`)
     * @param string                    $subjectType `contact` | `organization`
     * @param int                       $subjectId  the record the timeline hangs off
     * @param list<array<string,mixed>> $activities the subject's timeline (newest first)
     * @param string                    $nonce      the request CSP nonce
     */
    public static function render(string $csrf, string $page, string $subjectType, int $subjectId, array $activities, string $nonce): string
    {
        return self::styles($nonce)
            . '<section class="cr-timeline">'
            . '<h2>Activity</h2>'
            . self::form($csrf, $page, $subjectType, $subjectId)
            . self::list($csrf, $page, $subjectType, $subjectId, $activities)
            . '</section>';
    }

    private static function form(string $csrf, string $page, string $subjectType, int $subjectId): string
    {
        $kinds = '';
        foreach (Activities::KINDS as $k) {
            $kinds .= '<option value="' . self::e($k) . '">' . self::e(ucfirst($k)) . '</option>';
        }

        return '<form method="post" action="/admin/' . self::e($page) . '/activity-add" class="cr-act-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="subject_type" value="' . self::e($subjectType) . '">'
            . '<input type="hidden" name="subject_id" value="' . self::e((string) $subjectId) . '">'
            . '<div class="cr-act-row">'
            . '<label>Type<select name="kind">' . $kinds . '</select></label>'
            . '<label>When<input type="datetime-local" name="occurred_at"></label>'
            . '</div>'
            . '<label>Note<textarea name="body" rows="2" maxlength="20000" placeholder="What happened?"></textarea></label>'
            . '<div class="cr-actions"><button type="submit" class="nb-btn">Log activity</button></div>'
            . '</form>';
    }

    /** @param list<array<string,mixed>> $activities */
    private static function list(string $csrf, string $page, string $subjectType, int $subjectId, array $activities): string
    {
        if ($activities === []) {
            return '<p class="nb-muted">No activity logged yet.</p>';
        }

        $items = '';
        foreach ($activities as $a) {
            $body = (string) ($a['body'] ?? '');
            $meta = self::e(ucfirst((string) $a['kind'])) . ' · ' . self::e((string) $a['occurred_at']);
            if (($a['author'] ?? null) !== null && (string) $a['author'] !== '') {
                $meta .= ' · ' . self::e((string) $a['author']);
            }
            $items .= '<li class="cr-act">'
                . '<div class="cr-act-head"><span class="cr-act-meta">' . $meta . '</span>'
                . '<form method="post" action="/admin/' . self::e($page) . '/activity-delete" data-confirm="Delete this activity?">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . self::e((string) $a['id']) . '">'
                . '<input type="hidden" name="subject_type" value="' . self::e($subjectType) . '">'
                . '<input type="hidden" name="subject_id" value="' . self::e((string) $subjectId) . '">'
                . '<button type="submit" class="cr-link-danger">Delete</button></form></div>'
                . ($body !== '' ? '<p class="cr-act-body">' . self::e($body) . '</p>' : '')
                . '</li>';
        }

        return '<ul class="cr-act-list">' . $items . '</ul>';
    }

    private static function styles(string $nonce): string
    {
        return '<style nonce="' . self::e($nonce) . '">'
            . '.cr-timeline{max-width:44rem;margin:2rem 0 0;border-top:1px solid rgba(128,128,128,.2);padding-top:1rem}'
            . '.cr-act-form{display:flex;flex-direction:column;gap:.6rem;margin:0 0 1.5rem}'
            . '.cr-act-row{display:flex;gap:.75rem;flex-wrap:wrap}'
            . '.cr-act-form label{display:flex;flex-direction:column;gap:.25rem;flex:1 1 12rem;font-weight:600;font-size:.85rem}'
            . '.cr-act-form input,.cr-act-form textarea,.cr-act-form select{font:inherit;padding:.5rem .6rem;min-height:44px;box-sizing:border-box}'
            . '.cr-act-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.75rem}'
            . '.cr-act{border:1px solid rgba(128,128,128,.2);border-radius:8px;padding:.6rem .75rem}'
            . '.cr-act-head{display:flex;justify-content:space-between;align-items:center;gap:.5rem}'
            . '.cr-act-meta{font-size:.8rem;font-weight:700;opacity:.75}'
            . '.cr-act-body{margin:.4rem 0 0;white-space:pre-wrap}'
            . '.cr-link-danger{background:none;border:0;color:#c0392b;font:inherit;cursor:pointer;text-decoration:underline;padding:0;min-height:44px}'
            . '</style>';
    }

    /** Escape a value for HTML output (the admin CSP is nonce-only; every value is escaped). */
    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
