# OpenScene Project Log

Generated: $(date)

- Project started with `b419ace` (`Initial commit of OpenScene Engine`).
- OpenScene was built as a plugin-led runtime with layered boundaries: repositories/services/controllers/templates/React UI.
- Main runtime architecture uses plugin-owned SSR shell + React hydration (`templates/app-shell.php` + `build/assets/app.js`).
- REST namespace standardized under `openscene/v1` and retained throughout (no namespace split).
- Cursor-based/community feed patterns were preserved; no offset pagination introduced for core threaded flows.
- Parent-first comment threading constraints were retained with depth guard and per-view safeguards.

- Database schema foundation includes custom OpenScene tables:
- `openscene_communities`
- `openscene_posts`
- `openscene_comments`
- `openscene_votes`
- `openscene_vote_events`
- `openscene_events`
- `openscene_bans`
- `openscene_moderation_logs`
- `openscene_post_reports`
- `openscene_observability_logs` (added later for observability v1)
- Post type enum includes `event` and migration safeguards ensure enum backfill on existing installs.
- `posts.reports_count` is enforced as denormalized counter.
- DB version is currently `1.5.0` (`includes/Infrastructure/Database/MigrationRunner.php`).

- Migration history (major):
- Early core table setup in `MigrationRunner`.
- Reporting support and reports counter safety.
- Performance index migrations (`AddPerformanceIndexesV3`, `AddPerformanceIndexesV4`).
- Observability table migration (`AddObservabilityTableV1`).
- Idempotent migration strategy maintained.
- Cache version option introduced/maintained (`openscene_cache_version`).

- API/feature expansion completed:
- Global feed and search endpoints with sort controls (`hot/new/top`).
- Community feed endpoints with aligned ordering behavior.
- Post detail endpoint and comment endpoints (`top-level`, `children`) with depth safeguards.
- Event endpoints (`/events`, `/events/{id}` plus admin/mod write controls).
- Post voting endpoint (`/posts/{id}/vote`) with transactional score updates.
- Post reporting endpoint (`/posts/{id}/report`) and clear reports endpoint.
- Moderation endpoints for lock/sticky/ban/unban/logs/panel data.
- User profile endpoints (`/users/{username}`, posts/comments tabs).
- Recent activity endpoint added later (`/activity/recent`) for true activity rail data.

- Governance, access, and safety hardening:
- Custom capabilities introduced and wired (moderation/delete/lock/pin/ban scopes).
- Ban override guard (`requireNotBanned`) centralized in base REST controller.
- Nonce verification hardened on sensitive write routes.
- Capability checks standardized to `current_user_can(...)`; no role-string trust.
- Feature flag framework refactored to `reporting`, `voting`, `delete` only.
- Feature disabled semantics standardized with `openscene_feature_disabled` (403).
- Delete flag includes `manage_options` admin override.
- Soft-delete model for posts/comments stabilized and idempotency behavior hardened.
- Disabled community “hard disable” implemented server-authoritatively (feed exclusion + 404-style UI on blocked community/post routes).
- Registration exposure was locked down in favor of Join URL flow.

- Admin panel (wp-admin) implemented and integrated:
- Top-level OpenScene admin menu under `manage_options`.
- Tabs: `overview`, `settings`, `communities`, `analytics`, `system`, `observability`.
- Nonce-protected admin POST handlers and capability guards.
- Settings persisted in options (`openscene_admin_settings`, `openscene_community_rules`, `openscene_join_url` usage integrated).
- Branding controls include logo attachment + text fallback hierarchy.
- Community CRUD/admin controls implemented with enable/disable handling.
- Analytics and system diagnostic summaries added.
- Observability mode toggle added (`off|basic`) with admin-integrated controls.

- Observability v1 delivered:
- Observability logger service added.
- Slow-query and mutation-failure logging model introduced (basic mode).
- Log retention cleanup behavior integrated.
- Integrity checker service added (drift checks for score/comments/vote duplicates/orphans).
- Observability UI surfaced in admin tab.

- Theme/runtime work:
- Custom lightweight theme `openscene-classic-shell` created and activated.
- Theme contract intentionally keeps deterministic runtime surface for OpenScene.
- Plugin route interception remains authoritative (`TemplateLoader`).
- Compatibility audit confirmed OpenScene route behavior remains intact under this theme model.
- Controlled-runtime decision accepted (global dequeues/body_class policy intentionally strict for this deployment).

- UI evolution (major outcomes across pages):
- Global 3-column shell standardized (left rail / center feed / right rail).
- Header unified globally with shared nav/search/account controls.
- Create Post, Community Hub, Post Detail, Profile, Search, Communities views integrated into same runtime style system.
- Typography and spacing system tightened across platform.
- Lucide icon system adopted broadly and cleaned up.
- Community icon admin field added and wired into left rail rendering.
- “All Scenes” removed from left rail; “View All” retained.
- Left rail community ordering evolved to activity-aware behavior.
- Recent Activity card added and refined (time + upvotes + comments formatting).
- Feed card interaction model tightened (compact layout, action menu, report/delete behaviors, vote controls restored/aligned).
- Mobile drawer behavior and slide animation refined.
- Sticky behavior tuned for header/feed controls/rails.
- Right rail and left rail flicker/jump issues addressed structurally via stable render + cache-backed data.
- Topbar gradient border full-width fix applied in latest commit (`c4f20f5`).

- Search and route handling:
- `/search` route activation added for OpenScene search UI.
- Search endpoint integrated with sorting and pagination behavior.
- Removed default WP fallback behavior for OpenScene search route where applicable.
- SSR 404/forbidden experiences hardened for invalid/blocked routes.

- Reporting/moderation UX:
- Report badges shown conditionally.
- Moderator panel route and UI delivered with capability gating.
- Moderator actions use existing endpoints and backend permission enforcement.
- Clear reports flow updates UI and counters consistently.

- Caching/perf behavior:
- Versioned cache strategy used for feed/community invalidation.
- Write endpoints tied to cache invalidation flows.
- Rail data rendering stabilized with memory/session cache to avoid hydration flicker and layout jumps.
- No heavy client-only truth model introduced; server remains source of truth.

- Testing/verification work completed during project:
- Multiple runtime verifications were run for roles/capabilities/ban enforcement and vote behavior.
- Transactional integrity checks were implemented and extended in hardening passes.
- Security-focused audits and phased remediation were applied (Phase 1/2 hardening).
- DB index audit and migration work completed for scale-read paths.

- Commit timeline (from repo):
- `b419ace` Initial commit.
- `9158f41` sample fallback data removal.
- `3edad46` admin panel + hard-disable communities + feature flags.
- `464d242` nonce/security + REST hard-disable visibility + atomic vote scoring.
- `b03936b` phase-2 write/cache/SSR stabilization.
- `347bd30` DB performance index migration v1.4.0.
- `78f26bd` observability v1.
- `0c1749d` global feed desktop/mobile shell refinements.
- `b76a15e` rail shell/feed/community/post-detail UI unification.
- `1748021` broad platform UI refinements + icon/admin wiring + post detail tightening.
- `e6dd50e` deterministic left rail + All Scenes flicker elimination.
- `b1eae7e` true recent activity + `activity_at`.
- `fa316b2` rail stabilization + header nav sync.
- `c4f20f5` topbar full-width gradient bottom border.

- Current repository state (at last verified checkpoint):
- Branch: `main`
- Working tree: clean
- Latest commit: `c4f20f5`
