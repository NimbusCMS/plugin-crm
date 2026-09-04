<?php

declare(strict_types=1);

namespace NimbusCMS\Crm\Tests;

use NimbusCMS\Crm\ActivitiesAdmin;
use PHPUnit\Framework\TestCase;

/**
 * The timeline block renders author input (activity body + author name) to a
 * privileged operator — the one real risk is an un-escaped value (stored XSS).
 * The block is a pure renderer, so this needs no database: it proves hostile
 * values are escaped and that the forms carry the CSRF token and the subject.
 */
final class ActivitiesAdminTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function hostileTimeline(): array
    {
        return [[
            'id'           => 7,
            'subject_type' => 'contact',
            'subject_id'   => 3,
            'kind'         => 'note',
            'body'         => '<script>alert(1)</script>',
            'occurred_at'  => '2026-01-01 09:00:00',
            'author'       => '"><img src=x onerror=alert(1)>',
            'created_at'   => '2026-01-01 09:00:00',
        ]];
    }

    public function test_it_escapes_hostile_body_and_author(): void
    {
        $html = ActivitiesAdmin::render('CSRF123', 'crm', 'contact', 3, $this->hostileTimeline(), 'test-nonce');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'the body is escaped');
        self::assertStringNotContainsString('<img src=x', $html, 'the author is escaped');
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_forms_carry_the_csrf_token_and_subject(): void
    {
        $html = ActivitiesAdmin::render('CSRF123', 'crm-organizations', 'organization', 42, [], 'test-nonce');

        self::assertStringContainsString('value="CSRF123"', $html, 'the CSRF token is in the forms');
        self::assertStringContainsString('action="/admin/crm-organizations/activity-add"', $html);
        self::assertStringContainsString('value="organization"', $html, 'the subject type is posted');
        self::assertStringContainsString('value="42"', $html, 'the subject id is posted');
        self::assertStringContainsString('No activity logged yet.', $html);
    }

    public function test_the_style_block_carries_the_nonce(): void
    {
        $html = ActivitiesAdmin::render('CSRF123', 'crm', 'contact', 3, [], 'the-nonce');
        self::assertStringContainsString('<style nonce="the-nonce">', $html, 'inline style is nonce-tagged for the admin CSP');
    }
}
