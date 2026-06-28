<?php

namespace Platform\Inbox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * REST endpoint used by external clients (KyberOS Mac app, future iOS,
 * etc.) to discover the meeting that's happening right now for the
 * authenticated user. The Mac client calls this just before starting
 * a recording so the resulting Whisper upload can pass back the
 * matching InboxItem id and the server-side bridge links the two.
 */
class MeetingDiscoveryController extends Controller
{
    /**
     * GET /api/inbox/meetings/current
     *
     * Response 200: { inbox_item_id, source_session_id, subject, ... }
     * Response 204: no meeting active for this user right now
     */
    public function current(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Join inbox_items to user_connector_meeting_sessions on the
        // source_type/source_id columns the InboxIngestionService uses
        // when mapping calendar events. Filter to sessions where the
        // user owns the underlying connection AND the wall-clock window
        // straddles now().
        $row = DB::table('inbox_items as i')
            ->join('user_connector_meeting_sessions as s', function ($join) {
                $join->on('i.source_id', '=', 's.id')
                     ->where('i.source_type', '=', 'user_connector_meeting_session');
            })
            ->join('user_connector_connections as c', 'c.id', '=', 's.connection_id')
            ->where('c.owner_user_id', $user->id)
            ->where('i.team_id', $user->current_team_id)
            ->where('i.channel', 'meeting')
            ->where('s.start_at', '<=', now())
            ->where('s.end_at', '>=', now())
            ->orderBy('s.start_at', 'asc')
            ->select([
                'i.id as inbox_item_id',
                'i.uuid as inbox_item_uuid',
                'i.subject',
                's.id as session_id',
                's.external_event_id',
                's.organizer_address',
                's.organizer_name',
                's.start_at',
                's.end_at',
                's.is_online_meeting',
                's.online_meeting_url',
            ])
            ->first();

        if (!$row) {
            return response()->json(null, 204);
        }

        return response()->json([
            'inbox_item_id' => (int) $row->inbox_item_id,
            'inbox_item_uuid' => (string) $row->inbox_item_uuid,
            'subject' => (string) ($row->subject ?? ''),
            'session_id' => (int) $row->session_id,
            'external_event_id' => $row->external_event_id ? (string) $row->external_event_id : null,
            'organizer' => [
                'name' => $row->organizer_name,
                'address' => $row->organizer_address,
            ],
            'start_at' => $row->start_at,
            'end_at' => $row->end_at,
            'is_online_meeting' => (bool) $row->is_online_meeting,
            'online_meeting_url' => $row->online_meeting_url,
        ]);
    }
}
