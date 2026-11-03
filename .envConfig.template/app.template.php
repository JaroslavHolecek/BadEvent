<?php

declare(strict_types=1);

return [
    'app_name' => 'Bad Event Manager',
    'base_url' => 'https://badit.cz/TEST/BadEvent',
    'timezone' => 'Europe/Prague',

    // Organization logo shown in the page header and on exports: a path inside public/ (or a full https:// URL).
    'logo_path' => '/assets/images/logo-min.svg',

    'mail_from' => 'BadEvent@badit.cz',
    'email_from_name' => 'Bad Event Manager',

    // Used by the action create/edit forms (client-side only) to auto-fill the staff
    // start/end datetimes from the public ones, and the public end from its start.
    'action_defaults' => [
        'staff_buffer_minutes'     => 30,  // staff arrives this many minutes before public start, leaves this many after public end
        'default_duration_minutes' => 120, // used to fill public_end_at from public_start_at when left empty
    ],

    // Default time-range for the actions list's "Filtrovat podle času" filter, applied
    // when the visitor hasn't set date_from/date_to yet.
    'action_list_defaults' => [
        'days_back'    => 30,
        'days_forward' => 30,
    ],

    // After a form is rejected (or a status change lacks required data), the page waits this long
    // so the error message can be read, then smoothly scrolls to the highlighted field/section.
    // A missing key falls back to the same default as shown here.
    'form_feedback' => [
        'scroll_delay_ms'    => 1000, // pause before scrolling
        'scroll_duration_ms' => 450,  // length of the scroll animation — fast, but visibly moving
    ],

    // Thresholds of the time-based notifications (in-app + email), checked by cron/daily.php and
    // on every "Můj přehled" load. A missing key falls back to the same default as shown here.
    'notifications' => [
        'draft_reminder_days'     => [30, 7], // reminders this many days before the start of a still-Rozjednaná action
        'overdue_lookback_days'   => 30,      // Rozjednaná/Potvrzená action that ended at most this many days ago -> "change its status"
        'missing_room_days'       => 7,       // action starting within this many days with no confirmed room -> reminder
        'queue_first_notify_days' => 7,       // 1st in a room queue this many days without the room -> organizers
        'queue_first_admin_days'  => 14,      // 1st in a room queue this many days without the room -> administrators
        'email_retry_days'        => 2,       // emails that failed to send are retried for this many days
    ],
];
