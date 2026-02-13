# OpenScene Events Test Acceptance Criteria

## Unit Tests

1. PostService validation
- Rejects `type=event` when `event_date` missing.
- Rejects `type=event` when `venue_name` missing.
- Rejects malformed `event_date` and malformed `event_end_date`.
- Accepts `type=text|link|media` without event fields.

2. PostService transactional behavior
- Rolls back post insert when event insert fails.
- Commits both post and event rows when event payload is valid.

3. EventRepository behavior
- `create()` stores required event fields and links `post_id`.
- `update()` updates only provided fields.
- `findByPostIds()` returns minimal event summary map keyed by post id.
- `listByScope('upcoming'|'past')` returns expected ordering and cursor-safe boundaries.

4. Feed cursor helpers
- Cursor encode/decode maintains sort safety.
- Invalid cursor tokens are ignored safely.

## Integration Tests

1. Migration
- Activating plugin creates `wp_openscene_events` table.
- `wp_openscene_posts.type` supports `event` enum value.

2. POST `/openscene/v1/posts`
- `type=event` with required fields creates both post + event row.
- Missing required event fields returns `422`.
- Invalid nonce returns `403`.
- Missing capability returns `403`.
- Rate limit breach returns `429`.

3. GET `/openscene/v1/posts`
- Returns cursor metadata (`next_cursor`, `limit`).
- Event posts include `event_summary` with `event_date` and `venue_name`.

4. Event endpoints
- GET `/openscene/v1/events?scope=upcoming` returns upcoming sorted ascending by `event_date`.
- GET `/openscene/v1/events?scope=past` returns past sorted descending by `event_date`.
- GET `/openscene/v1/events/{id}` returns linked post metadata + event payload.
- POST/PATCH/DELETE `/openscene/v1/events` require moderator/admin capability path.

5. Cache consistency
- Creating/updating/deleting events bumps OpenScene cache version.
- Creating event posts invalidates feed caches via cache version bump.

6. UI integration
- Create Post Event tab submits event payload to existing `/openscene/v1/posts` endpoint.
- Successful publish returns success state and clears editor.
- Backend validation errors surface in UI without client-side model divergence.
