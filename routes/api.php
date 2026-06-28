<?php

use Platform\Inbox\Http\Controllers\MeetingDiscoveryController;

// GET /api/inbox/meetings/current → InboxItem für das aktuell laufende
// Meeting des authentifizierten Users. Wird vom KyberOS Mac-Client vor
// einer Aufnahme abgefragt, damit der Upload die meeting_inbox_item_id
// mit zurueckspielen kann.
Route::get('/meetings/current', [MeetingDiscoveryController::class, 'current'])
    ->name('inbox.api.meetings.current');
