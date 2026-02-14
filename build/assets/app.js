(function () {
  const root = document.getElementById('openscene-root');
  const commentsRoot = document.getElementById('openscene-comments-root');
  const userContentRoot = document.getElementById('openscene-user-content-root');
  const bootRoot = root || commentsRoot || userContentRoot;
  if (!bootRoot) return;

  const h = window.wp?.element?.createElement;
  const useState = window.wp?.element?.useState;
  const useEffect = window.wp?.element?.useEffect;
  const useRef = window.wp?.element?.useRef;
  const createRoot = window.wp?.element?.createRoot;
  const legacyRender = window.wp?.element?.render;
  const apiFetch = window.wp?.apiFetch;

  if (!h || !useState || !useEffect || !useRef || (!createRoot && !legacyRender) || !apiFetch) {
    if (bootRoot) {
      bootRoot.innerHTML = '<div style=\"padding:16px;color:#ff8a8a;font-family:system-ui,sans-serif\">OpenScene failed to initialize: missing WordPress runtime dependencies.</div>';
    }
    return;
  }

  const cfg = window.OpenSceneConfig || {};
  let bootContext = {};
  try {
    bootContext = JSON.parse(bootRoot.getAttribute('data-openscene-context') || '{}');
  } catch (e) {
    bootContext = {};
  }
  try {
    if (apiFetch && typeof apiFetch.use === 'function' && typeof apiFetch.createNonceMiddleware === 'function') {
      apiFetch.use(apiFetch.createNonceMiddleware(cfg.nonce || ''));
    }
  } catch (e) {
    // Nonce middleware is optional for initial render safety; failed setup should not blank the app.
  }

  function refreshNonce() {
    return window.fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) throw new Error('Nonce refresh failed');
        return res.text();
      })
      .then(function (nonce) {
        const trimmed = String(nonce || '').trim();
        if (!trimmed) throw new Error('Nonce refresh failed');
        cfg.nonce = trimmed;
        return trimmed;
      });
  }

  function apiRequest(args, retryCount) {
    const currentRetry = typeof retryCount === 'number' ? retryCount : 0;
    const req = Object.assign({}, args || {});
    req.headers = Object.assign({}, req.headers || {});
    if (cfg.nonce) {
      req.headers['X-WP-Nonce'] = cfg.nonce;
    }

    return apiFetch(req).catch(function (err) {
      const code = err && err.code ? String(err.code) : '';
      const isNonceError = code === 'rest_cookie_invalid_nonce' || code === 'openscene_invalid_nonce';
      if (!isNonceError || currentRetry > 0) {
        throw err;
      }

      return refreshNonce().then(function (newNonce) {
        const retriedReq = Object.assign({}, req, {
          headers: Object.assign({}, req.headers, { 'X-WP-Nonce': newNonce })
        });
        return apiFetch(retriedReq);
      });
    });
  }

  function timeAgo(utc) {
    const input = utc ? utc.replace(' ', 'T') + 'Z' : '';
    const date = new Date(input);
    if (Number.isNaN(date.getTime())) return 'just now';
    const mins = Math.max(1, Math.floor((Date.now() - date.getTime()) / 60000));
    if (mins < 60) return mins + 'm ago';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    return Math.floor(hrs / 24) + 'd ago';
  }

  function formatUtcForDisplay(utcString) {
    if (!utcString) return '';
    const date = new Date(utcString.replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return utcString;
    return date.toLocaleString();
  }

  function useApi(path) {
    const [state, setState] = useState({ loading: !!path, data: null, error: null });
    useEffect(() => {
      if (!path) {
        setState({ loading: false, data: null, error: null });
        return function () {};
      }
      let cancelled = false;
      setState({ loading: true, data: null, error: null });
      apiRequest({ path: path }).then((res) => {
        if (!cancelled) {
          setState({ loading: false, data: res && res.data ? res.data : res, error: null });
        }
      }).catch((err) => {
        if (!cancelled) {
          setState({ loading: false, data: null, error: err && err.message ? err.message : 'Request failed' });
        }
      });
      return function () { cancelled = true; };
    }, [path]);

    return state;
  }

  function localDateTimeToUtcString(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toISOString().slice(0, 19).replace('T', ' ');
  }

  function renderSafeHtml(tagName, className, html) {
    return h(tagName, {
      className: className,
      dangerouslySetInnerHTML: { __html: html || '' }
    });
  }

  function Icon(name, className) {
    return h('i', { 'data-lucide': name, className: 'ose-lucide' + (className ? ' ' + className : '') });
  }

  function initPostHeaderVote() {
    const root = document.getElementById('openscene-post-vote');
    if (!root || root.getAttribute('data-bound') === '1') {
      return;
    }

    const postId = Number(root.getAttribute('data-post-id') || 0);
    let score = Number(root.getAttribute('data-score') || 0);
    let userVote = Number(root.getAttribute('data-user-vote') || 0);
    const upBtn = root.querySelector('.ose-pd-post-vote-up');
    const downBtn = root.querySelector('.ose-pd-post-vote-down');
    const scoreEl = root.querySelector('.ose-pd-post-vote-score');
    if (!postId || !upBtn || !downBtn || !scoreEl) {
      return;
    }

    function renderState() {
      scoreEl.textContent = String(score);
      upBtn.classList.toggle('is-active-up', userVote === 1);
      downBtn.classList.toggle('is-active-down', userVote === -1);
    }

    function redirectToLogin() {
      const redirectTo = encodeURIComponent(window.location.href || '/openscene/');
      window.location.href = '/wp-login.php?redirect_to=' + redirectTo;
    }

    let inFlight = false;
    function submitVote(clickedValue) {
      if (inFlight) {
        return;
      }
      if (Number(cfg.userId || 0) <= 0) {
        redirectToLogin();
        return;
      }
      const previousScore = score;
      const previousVote = userVote;
      const nextVote = previousVote === clickedValue ? 0 : clickedValue;
      const delta = nextVote - previousVote;
      score = score + delta;
      userVote = nextVote;
      renderState();
      inFlight = true;

      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/vote',
        method: 'POST',
        data: { value: clickedValue }
      }).then(function (res) {
        const payload = res && res.data ? res.data : {};
        score = Number(payload.score || score);
        userVote = Number(payload.user_vote || 0);
        renderState();
        inFlight = false;
      }).catch(function () {
        score = previousScore;
        userVote = previousVote;
        renderState();
        inFlight = false;
      });
    }

    upBtn.addEventListener('click', function () { submitVote(1); });
    downBtn.addEventListener('click', function () { submitVote(-1); });
    root.setAttribute('data-bound', '1');
    renderState();
  }

  function brandLogo() {
    return h('a', { className: 'ose-brand', href: '/openscene/' }, [
      'scene',
      h('span', { key: 'dot' }, '.wtf')
    ]);
  }

  function TopHeader() {
    const isLoggedIn = Number(cfg && cfg.userId ? cfg.userId : 0) > 0;
    const canModerate = !!(cfg && cfg.permissions && cfg.permissions.canModerate);
    const currentUsernameRaw = cfg && cfg.currentUser && cfg.currentUser.username ? String(cfg.currentUser.username) : '';
    const currentUsername = currentUsernameRaw.toLowerCase().replace(/[^a-z0-9_\-.]/g, '');
    const avatarLabel = currentUsername ? currentUsername.slice(0, 2).toUpperCase() : 'GU';
    const profileHref = currentUsername ? ('/u/' + encodeURIComponent(currentUsername) + '/') : '/wp-login.php';
    const joinUrl = cfg && cfg.joinUrl ? String(cfg.joinUrl) : '';
    const searchParams = new URLSearchParams(window.location.search || '');
    const initialSearch = searchParams.get('q') || '';
    const [searchValue, setSearchValue] = useState(initialSearch);

    function submitSearch() {
      const term = String(searchValue || '').trim();
      if (term.length < 1) {
        return;
      }
      window.location.href = '/search?q=' + encodeURIComponent(term);
    }

    return h('header', { className: 'ose-topbar' },
      h('div', { className: 'ose-topbar-left' },
        brandLogo(),
        h('div', { className: 'ose-search' },
          Icon('search', 'ose-search-icon'),
          h('input', {
            type: 'search',
            placeholder: 'Search conversations...',
            'aria-label': 'Search conversations',
            value: searchValue,
            onChange: function (e) { setSearchValue(e.target.value); },
            onKeyDown: function (e) {
              if (e.key === 'Enter') {
                e.preventDefault();
                submitSearch();
              }
            }
          })
        )
      ),
      h('div', { className: 'ose-topbar-right' },
        isLoggedIn
          ? [
              h('button', { key: 'notif', className: 'ose-icon-btn', 'aria-label': 'Notifications' }, Icon('bell')),
              h('details', { key: 'menu', className: 'ose-avatar-menu' },
                h('summary', { className: 'ose-avatar-summary', 'aria-label': 'Open profile menu' },
                  h('span', { className: 'ose-avatar', 'aria-label': 'Open profile menu' }, avatarLabel)
                ),
                h('div', { className: 'ose-avatar-dropdown' },
                  h('a', { href: profileHref }, 'Profile'),
                  canModerate ? h('a', { href: '/moderator/' }, 'Moderator Panel') : null
                )
              )
            ]
          : (joinUrl ? h('a', { className: 'ose-join-btn', href: joinUrl, target: '_blank', rel: 'noopener noreferrer' }, 'JOIN') : null)
      )
    );
  }

  function LeftSidebar(props) {
    const communities = Array.isArray(props.communities) ? props.communities : [];

    return h('aside', { className: 'ose-left' },
      h('h3', { className: 'ose-side-title' }, 'Communities'),
      h('nav', { className: 'ose-community-list' },
        h('a', {
          key: 'all-scenes',
          className: 'ose-community-item is-active',
          href: '/openscene/?view=communities'
        },
        h('span', { className: 'ose-community-name' }, Icon('audio-lines', 'ose-community-icon'), 'All Scenes')
        ),
        communities.map(function (c, idx) {
          return h('a', {
            key: c.slug || c.id,
            className: 'ose-community-item',
            href: '/c/' + (c.slug || ('community-' + c.id))
          },
          h('span', { className: 'ose-community-name' }, Icon('music-4', 'ose-community-icon'), c.name),
          c.count ? h('span', { className: 'ose-community-count' }, c.count) : null
          );
        })
      ),
      h('div', { className: 'ose-create-card' },
        h('p', null, 'Bangalore\'s underground scene collective. Support your local artists.'),
        h('a', { className: 'ose-create-btn', href: '/openscene/?view=create' }, 'Create Post')
      )
    );
  }

  function FeedPost(props) {
    const post = props.post;
    const communityName = props.communityName || 'discussion';
    const author = post.author || 'sub_low';
    const userVote = Number(post.user_vote || 0);
    const isRemoved = String(post.status || '') === 'removed';
    const isPublished = String(post.status || '') === 'published';
    const canDeleteAnyPost = !!(cfg && cfg.permissions && cfg.permissions.canDeleteAnyPost);
    const isLoggedIn = Number(cfg.userId || 0) > 0;
    const userId = Number(cfg.userId || 0);
    const isOwner = userId > 0 && userId === Number(post.user_id || 0);
    const isReported = !!post.user_reported;
    const reportsCount = Number(post.reports_count || 0);

    return h('article', { className: 'ose-feed-post' },
      h('div', { className: 'ose-vote-rail' },
        h('button', {
          className: 'ose-vote-btn' + (userVote === 1 ? ' is-active-up' : ''),
          'aria-label': 'Upvote',
          disabled: isRemoved,
          onClick: function () { props.onVote(post.id, 1, userVote); }
        }, Icon('chevron-up')),
        h('strong', null, post.score >= 1000 ? (Math.round((post.score / 100)) / 10) + 'k' : String(post.score || 0)),
        h('button', {
          className: 'ose-vote-btn' + (userVote === -1 ? ' is-active-down' : ''),
          'aria-label': 'Downvote',
          disabled: isRemoved,
          onClick: function () { props.onVote(post.id, -1, userVote); }
        }, Icon('chevron-down'))
      ),
      h('div', { className: 'ose-post-content' },
        h('div', { className: 'ose-post-meta' },
          h('span', { className: 'ose-tag' }, String(communityName).toUpperCase()),
          h('span', { className: 'ose-dot' }, '•'),
          h('span', null, 'Posted by '),
          h('strong', null, author),
          h('span', null, ' · ' + timeAgo(post.created_at))
        ),
        h('a', { className: 'ose-post-title', href: '/post/' + post.id }, isRemoved ? '[removed]' : post.title),
        h('p', { className: 'ose-post-body' }, isRemoved ? '' : (post.body || '')),
        post.event_summary ? h('div', { className: 'ose-event-summary-inline' },
          Icon('calendar-days'),
          ' ' + formatUtcForDisplay(post.event_summary.event_date) + ' · ' + (post.event_summary.venue_name || '')
        ) : null,
        h('div', { className: 'ose-post-actions' },
          h('a', { href: '/post/' + post.id }, Icon('message-square'), (post.comment_count || 0) + ' comments'),
          reportsCount > 0 ? h('span', { className: 'ose-report-badge', 'aria-label': String(reportsCount) + ' reports' }, Icon('flag', 'ose-report-badge-icon'), String(reportsCount) + ' Reports') : null,
          h('button', { type: 'button' }, Icon('share-2'), 'Share'),
          h('button', { type: 'button' }, Icon('bookmark'), 'Save'),
          isLoggedIn && !isOwner && isPublished ? h('button', {
            type: 'button',
            onClick: function () { props.onReport(post.id); },
            disabled: isReported
          }, Icon('flag'), isReported ? 'Reported' : 'Report') : null,
          canDeleteAnyPost && !isRemoved ? h('button', { type: 'button', onClick: function () { props.onDelete(post.id); } }, Icon('trash-2'), 'Delete') : null
        )
      )
    );
  }

  function CenterFeed(props) {
    const [mode, setMode] = useState('hot');
    const feed = useApi('/openscene/v1/posts?sort=' + mode + '&page=1&per_page=20');
    const [optimisticVotes, setOptimisticVotes] = useState({});
    const [deletedPosts, setDeletedPosts] = useState({});
    const [reportedPosts, setReportedPosts] = useState({});

    const communityMap = {};
    (props.communities || []).forEach(function (c) {
      communityMap[c.id] = c.name;
    });

    let posts = [];
    if (Array.isArray(feed.data)) {
      posts = feed.data;
    }

    function loginRedirect() {
      const redirectTo = encodeURIComponent(window.location.href || '/openscene/');
      window.location.href = '/wp-login.php?redirect_to=' + redirectTo;
    }

    function handleVote(postId, clickedValue, currentVote) {
      if (Number(cfg.userId || 0) <= 0) {
        loginRedirect();
        return;
      }

      const effective = (currentVote === clickedValue) ? 0 : clickedValue;
      const delta = effective - currentVote;
      setOptimisticVotes(function (prev) {
        return Object.assign({}, prev, {
          [postId]: { user_vote: effective, delta: delta, pending: true }
        });
      });

      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/vote',
        method: 'POST',
        data: { value: clickedValue }
      }).then(function (res) {
        const payload = res && res.data ? res.data : {};
        setOptimisticVotes(function (prev) {
          return Object.assign({}, prev, {
            [postId]: {
              user_vote: Number(payload.user_vote || 0),
              absoluteScore: Number(payload.score || 0),
              pending: false
            }
          });
        });
      }).catch(function () {
        setOptimisticVotes(function (prev) {
          const copy = Object.assign({}, prev);
          delete copy[postId];
          return copy;
        });
      });
    }

    function handleDelete(postId) {
      if (!postId) return;
      if (!window.confirm('Delete this post?')) return;

      apiRequest({
        path: '/openscene/v1/posts/' + postId,
        method: 'DELETE'
      }).then(function () {
        setDeletedPosts(function (prev) {
          const copy = Object.assign({}, prev);
          copy[postId] = true;
          return copy;
        });
      }).catch(function () {});
    }

    function handleReport(postId) {
      if (!postId || reportedPosts[postId]) return;
      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/report',
        method: 'POST'
      }).then(function (res) {
        setReportedPosts(function (prev) {
          const copy = Object.assign({}, prev);
          copy[postId] = true;
          return copy;
        });
      }).catch(function () {});
    }

    return h('main', { className: 'ose-center' },
      h('div', { className: 'ose-feed-header' },
        h('div', { className: 'ose-feed-tabs' },
          h('button', { className: mode === 'hot' ? 'is-active' : '', onClick: function () { setMode('hot'); } }, 'Hot'),
          h('button', { className: mode === 'new' ? 'is-active' : '', onClick: function () { setMode('new'); } }, 'New'),
          h('button', { className: mode === 'top' ? 'is-active' : '', onClick: function () { setMode('top'); } }, 'Top')
        ),
        h('button', { className: 'ose-filter-btn', type: 'button', 'aria-label': 'Filter' }, Icon('sliders-horizontal'))
      ),
      h('div', { className: 'ose-feed-list' },
        feed.loading ? h('div', { className: 'ose-loading' }, 'Loading feed...') : null,
        feed.error ? h('div', { className: 'ose-loading' }, feed.error) : null,
        (!feed.loading && !feed.error && posts.length === 0) ? h('div', { className: 'ose-loading' }, 'No conversations yet.') : null,
        posts.map(function (post) {
          const optimistic = optimisticVotes[post.id] || null;
          const mergedPost = Object.assign({}, post);
          if (optimistic) {
            mergedPost.user_vote = optimistic.user_vote;
            if (typeof optimistic.absoluteScore === 'number') {
              mergedPost.score = optimistic.absoluteScore;
            } else {
              mergedPost.score = Number(post.score || 0) + Number(optimistic.delta || 0);
            }
          }
          if (deletedPosts[post.id]) {
            mergedPost.status = 'removed';
            mergedPost.title = '[removed]';
            mergedPost.body = '';
            mergedPost.user_vote = 0;
          }
          if (reportedPosts[post.id]) {
            mergedPost.user_reported = true;
            mergedPost.reports_count = Number(mergedPost.reports_count || 0) + 1;
          }
          return h(FeedPost, {
            key: post.id,
            post: mergedPost,
            communityName: communityMap[post.community_id] || post.type || 'discussion',
            onVote: handleVote,
            onDelete: handleDelete,
            onReport: handleReport
          });
        })
      )
    );
  }

  function SidebarRail() {
    const eventsRes = useApi('/openscene/v1/events?scope=upcoming&limit=3');
    const liveEvents = Array.isArray(eventsRes.data) ? eventsRes.data : [];
    const events = liveEvents.map(function (ev) {
      const date = new Date((ev.event_date || '').replace(' ', 'T') + 'Z');
      const month = Number.isNaN(date.getTime()) ? 'NA' : date.toLocaleString('en-US', { month: 'short' }).toUpperCase();
      const day = Number.isNaN(date.getTime()) ? '--' : String(date.getUTCDate()).padStart(2, '0');
      return {
        id: ev.id,
        month: month,
        day: day,
        title: ev.title || 'Untitled event',
        info: (ev.venue_name || ev.venue_address || 'Venue TBA') + ' · ' + formatUtcForDisplay(ev.event_date)
      };
    });

    const rules = [
      'No gatekeeping. Everyone was new once.',
      'Respect the artists and venue staff.',
      'No promotion of commercial mainstream events.',
      'High signal, low noise content only.'
    ];

    return h('div', { className: 'ose-right-rail' },
      h('section', { className: 'ose-widget' },
        h('div', { className: 'ose-widget-head' },
          h('h3', null, 'Upcoming Bangalore Events'),
          h('span', { className: 'ose-widget-icon ose-widget-icon-events' }, Icon('calendar-days'))
        ),
        h('div', { className: 'ose-events' },
          events.map(function (ev, idx) {
            return h('article', { className: 'ose-event', key: idx },
              h('div', { className: 'ose-event-date' },
                h('span', { className: 'ose-event-month' }, ev.month),
                h('span', { className: 'ose-event-day' }, ev.day)
              ),
              h('div', null,
                h('h4', null, h('a', { href: ev.id ? ('/openscene/?view=event&id=' + ev.id) : '#' }, ev.title)),
                h('p', null, ev.info)
              )
            );
          }),
          !eventsRes.loading && !eventsRes.error && events.length === 0 ? h('p', { className: 'ose-events-note' }, 'No upcoming events.') : null
        ),
        h('a', { className: 'ose-widget-btn', href: '/openscene/?view=events' }, 'View Calendar')
      ),
      h('section', { className: 'ose-widget' },
        h('div', { className: 'ose-widget-head' },
          h('h3', null, 'Community Rules'),
          h('span', { className: 'ose-widget-icon ose-widget-icon-rules' }, Icon('gavel'))
        ),
        h('ol', { className: 'ose-rules' },
          rules.map(function (rule, idx) {
            return h('li', { key: idx },
              h('span', null, String(idx + 1).padStart(2, '0')),
              h('p', null, rule)
            );
          })
        )
      ),
      h('footer', { className: 'ose-footer' },
        h('nav', null,
          h('a', { href: '#' }, 'About'),
          h('a', { href: '#' }, 'Guidelines'),
          h('a', { href: '#' }, 'Privacy'),
          h('a', { href: '#' }, 'Manifesto')
        ),
        h('p', null, '© 2026 scene.wtf — Bangalore Underground Collective')
      )
    );
  }

  function RightSidebar() {
    return h('aside', { className: 'ose-right' }, h(SidebarRail));
  }

  function EventsListPage() {
    const [scope, setScope] = useState('upcoming');
    const [cursor, setCursor] = useState('');
    const [items, setItems] = useState([]);
    const [nextCursor, setNextCursor] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(function () {
      let canceled = false;
      setLoading(true);
      setError('');
      const path = '/openscene/v1/events?scope=' + scope + '&limit=12' + (cursor ? ('&cursor=' + encodeURIComponent(cursor)) : '');
      apiRequest({ path: path }).then(function (res) {
        if (canceled) return;
        const rows = Array.isArray(res.data) ? res.data : [];
        setItems(function (prev) { return cursor ? prev.concat(rows) : rows; });
        setNextCursor(res.meta && res.meta.next_cursor ? res.meta.next_cursor : null);
        setLoading(false);
      }).catch(function (err) {
        if (canceled) return;
        setError(err && err.message ? err.message : 'Unable to load events.');
        setLoading(false);
      });
      return function () { canceled = true; };
    }, [scope, cursor]);

    return h('div', { className: 'ose-create-page' },
      h(TopHeader),
      h('main', { className: 'ose-events-main' },
        h('div', { className: 'ose-events-header' },
          h('h1', null, 'Events'),
          h('div', { className: 'ose-events-scope' },
            h('button', { className: scope === 'upcoming' ? 'is-active' : '', onClick: function () { setScope('upcoming'); setCursor(''); setItems([]); } }, 'Upcoming'),
            h('button', { className: scope === 'past' ? 'is-active' : '', onClick: function () { setScope('past'); setCursor(''); setItems([]); } }, 'Past')
          )
        ),
        loading && items.length === 0 ? h('p', { className: 'ose-events-note' }, 'Loading events...') : null,
        error ? h('p', { className: 'ose-events-note ose-events-error' }, error) : null,
        h('section', { className: 'ose-events-list' },
          items.map(function (ev) {
            return h('article', { className: 'ose-events-item', key: ev.id },
              h('div', { className: 'ose-events-item-date' },
                Icon('calendar-days'),
                h('span', null, formatUtcForDisplay(ev.event_date))
              ),
              h('h3', null, h('a', { href: '/openscene/?view=event&id=' + ev.id }, ev.title || 'Untitled event')),
              h('p', null, ev.venue_name || 'Venue TBA')
            );
          })
        ),
        nextCursor ? h('button', { className: 'ose-load-more', onClick: function () { setCursor(nextCursor); }, disabled: loading }, loading ? 'Loading...' : 'Load More') : null
      )
    );
  }

  function CommunitiesListPage() {
    const communitiesRes = useApi('/openscene/v1/communities?limit=100');
    const communities = Array.isArray(communitiesRes.data) ? communitiesRes.data : [];
    const sidebarCommunities = communities.map(function (c) {
      return {
        id: c.id,
        name: c.name,
        slug: c.slug,
        count: ''
      };
    });

    return h('div', { className: 'ose-scene-home' },
      h(TopHeader),
      h('div', { className: 'ose-scene-grid' },
        h(LeftSidebar, { communities: sidebarCommunities }),
        h('main', { className: 'ose-center' },
          h('section', { className: 'ose-communities-card' },
            h('header', { className: 'ose-communities-header' },
              h('h1', null, 'All Communities'),
              h('p', null, 'Browse and open a community feed.')
            ),
            communitiesRes.loading ? h('p', { className: 'ose-events-note' }, 'Loading communities...') : null,
            communitiesRes.error ? h('p', { className: 'ose-events-note ose-events-error' }, communitiesRes.error) : null,
            !communitiesRes.loading && !communitiesRes.error && communities.length === 0
              ? h('p', { className: 'ose-events-note' }, 'No communities yet.')
              : null,
            h('div', { className: 'ose-communities-list' },
              communities.map(function (community) {
                return h('a', {
                  key: community.id,
                  className: 'ose-community-row',
                  href: '/c/' + community.slug
                },
                h('span', { className: 'ose-community-row-icon' }, Icon('music-4')),
                h('span', { className: 'ose-community-row-text' },
                  h('strong', null, community.name),
                  h('small', null, '/' + community.slug)
                ),
                h('span', { className: 'ose-community-row-open' }, 'Open')
                );
              })
            )
          )
        ),
        h(RightSidebar)
      )
    );
  }

  function EventDetailPage(props) {
    const eventId = Number(props.eventId || 0);
    const eventRes = useApi('/openscene/v1/events/' + eventId);
    const event = eventRes.data || null;

    return h('div', { className: 'ose-create-page' },
      h(TopHeader),
      h('main', { className: 'ose-events-main' },
        !event && eventRes.loading ? h('p', { className: 'ose-events-note' }, 'Loading event...') : null,
        !event && eventRes.error ? h('p', { className: 'ose-events-note ose-events-error' }, eventRes.error) : null,
        event ? h('article', { className: 'ose-event-detail-card' },
          h('p', { className: 'ose-event-detail-kicker' }, 'Event'),
          h('h1', null, event.title || 'Untitled event'),
          h('p', { className: 'ose-event-detail-meta' }, 'When: ' + formatUtcForDisplay(event.event_date)),
          event.event_end_date ? h('p', { className: 'ose-event-detail-meta' }, 'Ends: ' + formatUtcForDisplay(event.event_end_date)) : null,
          h('p', { className: 'ose-event-detail-meta' }, 'Venue: ' + (event.venue_name || 'TBA')),
          event.venue_address ? h('p', { className: 'ose-event-detail-meta' }, event.venue_address) : null,
          event.ticket_url ? h('p', { className: 'ose-event-detail-meta' }, h('a', { href: event.ticket_url, target: '_blank', rel: 'noreferrer' }, 'Tickets')) : null,
          h('div', { className: 'ose-event-detail-body' }, event.body || ''),
          h('div', { className: 'ose-event-detail-actions' },
            h('a', { className: 'ose-load-more', href: '/post/' + event.post_id }, 'Open Post Thread'),
            h('a', { className: 'ose-load-more', href: '/openscene/?view=events' }, 'Back to Events')
          )
        ) : null
      )
    );
  }

  function CommunityPostItem(props) {
    const post = props.post;
    const author = (post.author || 'anonymous').toLowerCase();
    const isRemoved = String(post.status || '') === 'removed';
    const isPublished = String(post.status || '') === 'published';
    const isLoggedIn = Number(cfg.userId || 0) > 0;
    const canDeleteAnyPost = !!(cfg && cfg.permissions && cfg.permissions.canDeleteAnyPost);
    const canModerate = !!(cfg && cfg.permissions && cfg.permissions.canModerate);
    const isOwner = Number(cfg.userId || 0) > 0 && Number(cfg.userId || 0) === Number(post.user_id || 0);
    const isReported = !!post.user_reported;
    const reportsCount = Number(post.reports_count || 0);
    const canReport = isLoggedIn && !isRemoved;
    const canDelete = (canDeleteAnyPost || canModerate) && !isRemoved;

    return h('article', { className: 'ose-community-post-item' },
      h('div', { className: 'ose-community-vote' },
        h('button', { className: 'ose-vote-btn', type: 'button', 'aria-label': 'Upvote' }, Icon('chevron-up')),
        h('strong', null, post.score >= 1000 ? (Math.round((post.score / 100)) / 10) + 'k' : String(post.score || 0)),
        h('button', { className: 'ose-vote-btn', type: 'button', 'aria-label': 'Downvote' }, Icon('chevron-down'))
      ),
      h('div', { className: 'ose-community-post-body' },
        h('div', { className: 'ose-community-post-meta' },
          h('span', null, 'u/' + author),
          h('i', null, '•'),
          h('span', null, timeAgo(post.created_at).toUpperCase())
        ),
        h('a', { className: 'ose-community-post-title', href: '/post/' + post.id }, isRemoved ? '[removed]' : (post.title || 'Untitled thread')),
        !isRemoved && post.body ? h('p', { className: 'ose-community-post-excerpt' }, post.body) : null,
        h('div', { className: 'ose-community-post-actions' },
          h('a', { href: '/post/' + post.id }, Icon('message-square'), (post.comment_count || 0) + ' comments'),
          reportsCount > 0 ? h('span', { className: 'ose-report-badge', 'aria-label': String(reportsCount) + ' reports' }, Icon('flag', 'ose-report-badge-icon'), String(reportsCount) + ' Reports') : null,
          h('button', { type: 'button' }, Icon('share-2'), 'Share'),
          h('button', { type: 'button' }, Icon('bookmark'), 'Save'),
          canReport ? h('button', {
            type: 'button',
            onClick: function () { props.onReport(post.id); },
            disabled: isReported
          }, Icon('flag'), isReported ? 'Reported' : 'Report') : null,
          canDelete ? h('button', {
            type: 'button',
            onClick: function () { props.onDelete(post.id); }
          }, Icon('trash-2'), 'Delete') : null
        )
      ),
      h('div', { className: 'ose-community-post-thumb', 'aria-hidden': 'true' }, Icon('audio-lines'))
    );
  }

  function CommunityHubPage(props) {
    const slug = String(props.communitySlug || '').trim();
    const [sortMode, setSortMode] = useState('hot');
    const [cursor, setCursor] = useState('');
    const [items, setItems] = useState([]);
    const [nextCursor, setNextCursor] = useState(null);
    const [loadingPosts, setLoadingPosts] = useState(false);
    const [postsError, setPostsError] = useState('');
    const [reportedPosts, setReportedPosts] = useState({});
    const [deletedPosts, setDeletedPosts] = useState({});

    const communityRes = useApi('/openscene/v1/communities/' + encodeURIComponent(slug));
    const community = communityRes.data || null;

    const communityId = community && community.id ? Number(community.id) : 0;
    useEffect(function () {
      setCursor('');
      setItems([]);
      setNextCursor(null);
      setPostsError('');
    }, [communityId, sortMode]);

    useEffect(function () {
      if (!communityId) {
        return;
      }

      let canceled = false;
      setLoadingPosts(true);
      setPostsError('');

      const path = '/openscene/v1/communities/' + communityId + '/posts?sort=' + encodeURIComponent(sortMode) + '&limit=20' + (cursor ? ('&cursor=' + encodeURIComponent(cursor)) : '');
      apiRequest({ path: path }).then(function (res) {
        if (canceled) return;
        const rows = Array.isArray(res.data) ? res.data : [];
        setItems(function (prev) { return cursor ? prev.concat(rows) : rows; });
        setNextCursor(res.meta && res.meta.next_cursor ? res.meta.next_cursor : null);
        setLoadingPosts(false);
      }).catch(function (err) {
        if (canceled) return;
        setPostsError(err && err.message ? err.message : 'Unable to load community feed.');
        setLoadingPosts(false);
      });

      return function () { canceled = true; };
    }, [communityId, sortMode, cursor]);

    function reportPost(postId) {
      if (!postId || reportedPosts[postId]) {
        return;
      }
      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/report',
        method: 'POST'
      }).then(function () {
        setReportedPosts(function (prev) {
          const copy = Object.assign({}, prev);
          copy[postId] = true;
          return copy;
        });
      }).catch(function () {});
    }

    function deletePost(postId) {
      if (!postId) return;
      if (!window.confirm('Delete this post?')) return;
      apiRequest({
        path: '/openscene/v1/posts/' + postId,
        method: 'DELETE'
      }).then(function () {
        setDeletedPosts(function (prev) {
          const copy = Object.assign({}, prev);
          copy[postId] = true;
          return copy;
        });
      }).catch(function () {});
    }

    function parseRules(rawRules) {
      if (!rawRules) {
        return [
          'Underground focus only.',
          'No spam or low-effort promotion.',
          'Keep feedback constructive.',
        ];
      }

      try {
        const decoded = JSON.parse(rawRules);
        if (Array.isArray(decoded)) {
          return decoded.filter(Boolean).slice(0, 5);
        }
      } catch (e) {
        // fall through
      }

      return String(rawRules)
        .split(/\r?\n+/)
        .map(function (line) { return line.trim(); })
        .filter(Boolean)
        .slice(0, 5);
    }

    const rules = parseRules(community ? community.rules : '');
    const communityName = community && community.slug ? ('c/' + community.slug) : 'c/' + slug;

    return h('div', { className: 'ose-community-page' },
      h(TopHeader),
      h('main', { className: 'ose-community-main' },
        h('div', { className: 'ose-community-grid' },
          h('section', { className: 'ose-community-left' },
            h('header', { className: 'ose-community-header' },
              h('div', { className: 'ose-community-kicker' }, 'Community'),
              h('h1', null, communityName),
              h('p', null, (community && community.description) ? community.description : 'Community feed for local scene discussions.'),
              h('div', { className: 'ose-community-actions' },
                h('button', { type: 'button' }, 'Follow'),
                h('button', { type: 'button', className: 'ose-community-notify', 'aria-label': 'Notifications' }, Icon('bell'))
              )
            ),
            h('div', { className: 'ose-community-feed-controls' },
              h('div', { className: 'ose-community-sort' },
                h('button', {
                  type: 'button',
                  className: sortMode === 'new' ? 'is-active' : '',
                  onClick: function () { setSortMode('new'); }
                }, 'New'),
                h('button', {
                  type: 'button',
                  className: sortMode === 'hot' ? 'is-active' : '',
                  onClick: function () { setSortMode('hot'); }
                }, 'Hot'),
                h('button', {
                  type: 'button',
                  className: sortMode === 'top' ? 'is-active' : '',
                  onClick: function () { setSortMode('top'); }
                }, 'Top')
              ),
              h('a', { className: 'ose-community-new-thread', href: '/openscene/?view=create' }, Icon('plus'), 'New Thread')
            ),
            communityRes.loading ? h('p', { className: 'ose-events-note' }, 'Loading community...') : null,
            communityRes.error ? h('p', { className: 'ose-events-note ose-events-error' }, communityRes.error) : null,
            loadingPosts && items.length === 0 ? h('p', { className: 'ose-events-note' }, 'Loading threads...') : null,
            postsError ? h('p', { className: 'ose-events-note ose-events-error' }, postsError) : null,
            h('div', { className: 'ose-community-posts' },
              items.map(function (post) {
                const merged = Object.assign({}, post);
                if (reportedPosts[post.id]) {
                  merged.user_reported = true;
                  merged.reports_count = Number(merged.reports_count || 0) + 1;
                }
                if (deletedPosts[post.id]) {
                  merged.status = 'removed';
                  merged.title = '[removed]';
                  merged.body = '';
                  merged.user_vote = 0;
                }
                return h(CommunityPostItem, { key: post.id, post: merged, onReport: reportPost, onDelete: deletePost });
              })
            ),
            nextCursor ? h('div', { className: 'ose-community-load-more-wrap' },
              h('button', {
                type: 'button',
                className: 'ose-community-load-more',
                disabled: loadingPosts,
                onClick: function () { setCursor(nextCursor); }
              }, loadingPosts ? 'Loading...' : 'Load More Archives')
            ) : null
          ),
          h('aside', { className: 'ose-community-right' },
            h('section', { className: 'ose-community-panel' },
              h('h3', null, 'About'),
              h('div', { className: 'ose-community-stats' },
                h('div', null,
                  h('strong', null, String(items.length)),
                  h('span', null, 'Threads Loaded')
                ),
                h('div', null,
                  h('strong', null, slug.toUpperCase()),
                  h('span', null, 'Active Hub')
                )
              ),
              h('p', null, (community && community.description) ? community.description : 'Community details will appear here.')
            ),
            h('section', { className: 'ose-community-panel' },
              h('h3', null, 'Guidelines'),
              h('ol', { className: 'ose-community-guidelines' },
                rules.map(function (rule, idx) {
                  return h('li', { key: idx },
                    h('span', null, String(idx + 1).padStart(2, '0')),
                    h('p', null, rule)
                  );
                })
              )
            ),
            h('section', { className: 'ose-community-panel' },
              h('h3', null, 'Curators'),
              h('div', { className: 'ose-community-curators' },
                h('div', null, h('span', null, 'u/void_runner'), h('small', null, 'Admin')),
                h('div', null, h('span', null, 'u/dark_matter'), h('small', null, 'Mod')),
                h('div', null, h('span', null, 'u/analog_soul'), h('small', null, 'Mod'))
              ),
              h('button', { type: 'button', className: 'ose-community-curators-btn' }, 'View All')
            ),
            h('footer', { className: 'ose-community-side-footer' },
              h('nav', null,
                h('a', { href: '#' }, 'Privacy'),
                h('a', { href: '#' }, 'Content Policy'),
                h('a', { href: '#' }, 'Contact')
              ),
              h('p', null, '© 2026 scene.wtf hub / bangalore')
            )
          )
        )
      )
    );
  }

  function PostCommentNode(props) {
    const comment = props.comment;
    const depth = Number(comment.depth || 0);
    const hasChildren = Number(comment.child_count || 0) > 0;
    const childState = props.childState || { items: [], loading: false, hasMore: false, open: false, page: 1 };
    const canReply = depth < 5 && !!props.canReply;
    const isHighlighted = props.highlightCommentId === comment.id;

    const meta = 'u/user_' + (comment.user_id || 0) + ' • ' + timeAgo(comment.created_at);

    return h('div', { id: 'comment-' + comment.id, className: 'ose-pd-comment-node depth-' + depth + (isHighlighted ? ' is-highlighted' : '') },
      h('div', { className: 'ose-pd-comment-line' }),
      h('div', { className: 'ose-pd-comment-content' },
        h('div', { className: 'ose-pd-comment-meta' }, meta),
        renderSafeHtml('div', 'ose-pd-comment-body', comment.body || ''),
        h('div', { className: 'ose-pd-comment-actions' },
          canReply ? h('button', {
            type: 'button',
            onClick: function () { props.onReplyToggle(comment.id); }
          }, 'Reply') : null,
          hasChildren ? h('button', {
            type: 'button',
            onClick: function () {
              props.onToggleChildren(comment.id);
              if (!childState.open && childState.items.length === 0) {
                props.onLoadChildren(comment.id, 1);
              }
            }
          }, childState.open ? 'Hide Replies' : ('Show Replies (' + (comment.child_count || 0) + ')')) : null
        ),
        canReply && props.replyParentId === comment.id ? h('div', { className: 'ose-pd-reply-box' },
          h('textarea', {
            id: 'ose-reply-' + comment.id,
            value: props.replyBody,
            placeholder: 'Write a reply...',
            onChange: function (e) { props.onReplyBodyChange(e.target.value); }
          }),
          h('div', { className: 'ose-pd-reply-actions' },
            h('button', { type: 'button', onClick: props.onReplyCancel }, 'Cancel'),
            h('button', {
              type: 'button',
              onClick: function () { props.onSubmitReply(comment.id); },
              disabled: props.submittingReply
            }, props.submittingReply ? 'Posting...' : 'Post Reply')
          )
        ) : null,
        childState.open ? h('div', { className: 'ose-pd-children' },
          childState.items.map(function (child) {
            return h(PostCommentNode, {
              key: child.id,
              comment: child,
              canReply: props.canReply,
              childState: props.childrenMap[child.id] || { items: [], loading: false, hasMore: false, open: false, page: 1 },
              childrenMap: props.childrenMap,
              replyParentId: props.replyParentId,
              replyBody: props.replyBody,
              submittingReply: props.submittingReply,
              highlightCommentId: props.highlightCommentId,
              onReplyToggle: props.onReplyToggle,
              onReplyBodyChange: props.onReplyBodyChange,
              onReplyCancel: props.onReplyCancel,
              onSubmitReply: props.onSubmitReply,
              onToggleChildren: props.onToggleChildren,
              onLoadChildren: props.onLoadChildren
            });
          }),
          childState.loading ? h('p', { className: 'ose-pd-inline-note' }, 'Loading replies...') : null,
          childState.error ? h('div', { className: 'ose-pd-child-error' },
            h('span', null, 'Failed to load replies.'),
            h('button', {
              type: 'button',
              onClick: function () { props.onLoadChildren(comment.id, childState.page || 1); }
            }, 'Retry')
          ) : null,
          childState.hasMore ? h('button', {
            type: 'button',
            className: 'ose-pd-load-children',
            onClick: function () { props.onLoadChildren(comment.id, (childState.page || 1) + 1); },
            disabled: childState.loading
          }, childState.loading ? 'Loading...' : 'Load More Replies') : null
        ) : null
      )
    );
  }

  function PostDetailPage(props) {
    const postId = Number(props.postId || 0);
    const postRes = useApi('/openscene/v1/posts/' + postId);
    const initialCountRaw = props.initialCommentCount || '0';
    const initialCommentCount = Number(initialCountRaw) > 0 ? Number(initialCountRaw) : 0;
    const postStatus = commentsRoot ? String(commentsRoot.getAttribute('data-post-status') || 'published') : 'published';
    const postUserId = commentsRoot ? Number(commentsRoot.getAttribute('data-post-user-id') || 0) : 0;
    const initialReported = commentsRoot ? String(commentsRoot.getAttribute('data-user-reported') || '0') === '1' : false;
    const canReply = postStatus !== 'removed';
    const canReport = Number(cfg.userId || 0) > 0 && Number(cfg.userId || 0) !== postUserId && postStatus === 'published';
    const childInFlightRef = useRef({});

    const [commentSort, setCommentSort] = useState('top');
    const [topPage, setTopPage] = useState(1);
    const [topComments, setTopComments] = useState([]);
    const [topLoading, setTopLoading] = useState(false);
    const [topError, setTopError] = useState('');
    const [topHasMore, setTopHasMore] = useState(false);

    const [commentBody, setCommentBody] = useState('');
    const [commentSubmitting, setCommentSubmitting] = useState(false);
    const [commentStatus, setCommentStatus] = useState('');

    const [replyParentId, setReplyParentId] = useState(0);
    const [replyBody, setReplyBody] = useState('');
    const [replySubmitting, setReplySubmitting] = useState(false);

    const [childrenMap, setChildrenMap] = useState({});
    const [highlightCommentId, setHighlightCommentId] = useState(0);
    const [reported, setReported] = useState(initialReported);
    const [reportCount, setReportCount] = useState(0);
    const postReportsCount = postRes && postRes.data ? Number(postRes.data.reports_count || 0) : 0;

    function sortParam(mode) {
      return mode === 'top' ? 'score' : 'created_at';
    }

    function normalizeRows(rows, mode) {
      if (! Array.isArray(rows)) {
        return [];
      }
      if (mode === 'new') {
        return rows.slice().reverse();
      }
      return rows;
    }

    function loadTop(page) {
      if (!postId) return;
      setTopLoading(true);
      setTopError('');
      const path = '/openscene/v1/posts/' + postId + '/comments?sort=' + sortParam(commentSort) + '&page=' + page + '&per_page=20';
      apiRequest({ path: path }).then(function (res) {
        const rows = normalizeRows(Array.isArray(res.data) ? res.data : [], commentSort);
        setTopComments(function (prev) { return page > 1 ? prev.concat(rows) : rows; });
        setTopHasMore(rows.length === 20 && (page * 20) < 500);
        setTopLoading(false);
      }).catch(function (err) {
        setTopLoading(false);
        setTopError(err && err.message ? err.message : 'Unable to load comments.');
      });
    }

    function loadChildren(parentId, page) {
      if (!postId || !parentId) return;
      const reqKey = parentId + ':' + page + ':' + commentSort;
      if (childInFlightRef.current[reqKey]) {
        return;
      }
      childInFlightRef.current[reqKey] = true;
      setChildrenMap(function (prev) {
        const curr = prev[parentId] || { items: [], page: 1, hasMore: false, loading: false, error: '', open: true };
        const next = Object.assign({}, curr, { loading: true, open: true, error: '' });
        return Object.assign({}, prev, { [parentId]: next });
      });

      const path = '/openscene/v1/posts/' + postId + '/comments/' + parentId + '/children?sort=' + sortParam(commentSort) + '&page=' + page + '&per_page=20';
      apiRequest({ path: path }).then(function (res) {
        const rows = normalizeRows(Array.isArray(res.data) ? res.data : [], commentSort);
        setChildrenMap(function (prev) {
          const curr = prev[parentId] || { items: [], page: 1, hasMore: false, loading: false, error: '', open: true };
          return Object.assign({}, prev, {
            [parentId]: {
              items: page > 1 ? curr.items.concat(rows) : rows,
              page: page,
              hasMore: rows.length === 20 && (page * 20) < 500,
              loading: false,
              error: '',
              open: true
            }
          });
        });
        delete childInFlightRef.current[reqKey];
      }).catch(function (err) {
        setChildrenMap(function (prev) {
          const curr = prev[parentId] || { items: [], page: 1, hasMore: false, loading: false, error: '', open: true };
          return Object.assign({}, prev, {
            [parentId]: Object.assign({}, curr, { loading: false, error: err && err.message ? err.message : 'Unable to load replies.', open: true })
          });
        });
        delete childInFlightRef.current[reqKey];
      });
    }

    useEffect(function () {
      setTopPage(1);
      setTopComments([]);
      setTopHasMore(false);
      setTopError('');
      setChildrenMap({});
      setHighlightCommentId(0);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }, [postId, commentSort]);

    useEffect(function () {
      loadTop(topPage);
    }, [postId, commentSort, topPage]);

    useEffect(function () {
      if (!postId || !window.location.hash) {
        return;
      }
      const target = window.location.hash.slice(1);
      if (!target) {
        return;
      }
      window.setTimeout(function () {
        const el = document.getElementById(target);
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          el.classList.add('is-highlighted');
          window.setTimeout(function () { el.classList.remove('is-highlighted'); }, 1800);
        }
      }, 120);
    }, [postId, topComments.length]);

    useEffect(function () {
      if (replyParentId > 0) {
        window.setTimeout(function () {
          const input = document.getElementById('ose-reply-' + replyParentId);
          if (input && typeof input.focus === 'function') {
            input.focus();
          }
        }, 50);
      }
    }, [replyParentId]);

    useEffect(function () {
      if (highlightCommentId <= 0) {
        return;
      }
      window.setTimeout(function () {
        const el = document.getElementById('comment-' + highlightCommentId);
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }, 120);
    }, [highlightCommentId, topComments.length, childrenMap]);

    useEffect(function () {
      setReportCount(postReportsCount);
    }, [postReportsCount]);

    useEffect(function () {
      const metaLine = document.querySelector('.ose-pd-header .ose-pd-meta');
      if (!metaLine) return;
      const existing = metaLine.querySelector('.ose-report-badge-inline');
      if (existing && existing.parentNode) {
        existing.parentNode.removeChild(existing);
      }
      if (reportCount <= 0) return;
      const badge = document.createElement('span');
      badge.className = 'ose-report-badge ose-report-badge-inline';
      badge.setAttribute('aria-label', String(reportCount) + ' reports');
      badge.innerHTML = '<svg class="ose-lucide ose-report-badge-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V4s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>' + String(reportCount) + ' Reports';
      metaLine.appendChild(badge);
    }, [reportCount]);

    function postComment(parentId) {
      const body = parentId ? replyBody : commentBody;
      if (!body.trim()) {
        setCommentStatus('Comment body cannot be empty.');
        return;
      }
      if (parentId) {
        setReplySubmitting(true);
      } else {
        setCommentSubmitting(true);
        setCommentStatus('');
      }
      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/comments',
        method: 'POST',
        data: parentId ? { body: body, parent_id: parentId } : { body: body }
      }).then(function (res) {
        const newId = res && res.data ? Number(res.data.id || 0) : 0;
        if (parentId) {
          setReplySubmitting(false);
          setReplyBody('');
          setReplyParentId(0);
          loadChildren(parentId, 1);
          if (newId > 0) {
            setHighlightCommentId(newId);
          }
        } else {
          setCommentSubmitting(false);
          setCommentBody('');
          setCommentStatus('Comment posted.');
          setTopPage(1);
          loadTop(1);
          if (newId > 0) {
            setHighlightCommentId(newId);
          }
        }
      }).catch(function (err) {
        const message = err && err.message ? err.message : 'Unable to post comment.';
        if (parentId) {
          setReplySubmitting(false);
          setCommentStatus(message);
        } else {
          setCommentSubmitting(false);
          setCommentStatus(message);
        }
      });
    }

    function toggleChildren(commentId) {
      setChildrenMap(function (prev) {
        const curr = prev[commentId] || { items: [], page: 1, hasMore: false, loading: false, error: '', open: false };
        return Object.assign({}, prev, {
          [commentId]: Object.assign({}, curr, { open: !curr.open })
        });
      });
    }

    function submitReport() {
      if (!canReport || reported) return;
      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/report',
        method: 'POST'
      }).then(function (res) {
        const nextCount = Number(res && res.data && res.data.reports_count ? res.data.reports_count : 0);
        setReported(true);
        setReportCount(nextCount > 0 ? nextCount : 1);
      }).catch(function () {});
    }

    const sortButtons = [
      { key: 'top', label: 'Top' },
      { key: 'new', label: 'New' },
      { key: 'old', label: 'Old' }
    ];

    return h('section', { className: 'ose-pd-comments-wrap' },
          h('div', { className: 'ose-pd-comments-head' },
            h('h2', null, String(Math.max(initialCommentCount, topComments.length)) + ' Comments'),
            canReport ? h('button', {
              type: 'button',
              className: 'ose-pd-report-btn',
              onClick: submitReport,
              disabled: reported
            }, reported ? 'Reported' : 'Report') : null,
            h('div', { className: 'ose-pd-comment-sort' },
              sortButtons.map(function (btn) {
                return h('button', {
                  key: btn.key,
                  type: 'button',
                  className: commentSort === btn.key ? 'is-active' : '',
                  onClick: function () { setCommentSort(btn.key); }
                }, btn.label);
              })
            )
          ),
          canReply ? h('div', { className: 'ose-pd-comment-editor' },
            h('textarea', {
              value: commentBody,
              placeholder: 'Add your thoughts...',
              onChange: function (e) { setCommentBody(e.target.value); }
            }),
            h('div', { className: 'ose-pd-comment-editor-actions' },
              h('button', {
                type: 'button',
                onClick: function () { postComment(0); },
                disabled: commentSubmitting
              }, commentSubmitting ? 'Posting...' : 'Post Comment')
            ),
            commentStatus ? h('p', { className: 'ose-pd-status' }, commentStatus) : null
          ) : h('p', { className: 'ose-pd-status' }, 'This post has been removed. New comments are disabled.'),
          h('div', { className: 'ose-pd-comments-list' },
            topComments.map(function (comment) {
              return h(PostCommentNode, {
                key: comment.id,
                comment: comment,
                canReply: canReply,
                childState: childrenMap[comment.id] || { items: [], loading: false, hasMore: false, open: false, page: 1 },
                childrenMap: childrenMap,
                replyParentId: replyParentId,
                replyBody: replyBody,
                submittingReply: replySubmitting,
                highlightCommentId: highlightCommentId,
                onReplyToggle: function (commentId) {
                  if (replyParentId === commentId) {
                    setReplyParentId(0);
                    setReplyBody('');
                    return;
                  }
                  setReplyParentId(commentId);
                  setReplyBody('');
                },
                onReplyBodyChange: setReplyBody,
                onReplyCancel: function () { setReplyParentId(0); setReplyBody(''); },
                onSubmitReply: postComment,
                onToggleChildren: toggleChildren,
                onLoadChildren: loadChildren
              });
            }),
            topLoading && topComments.length === 0 ? h('p', { className: 'ose-events-note' }, 'Loading comments...') : null,
            topError ? h('p', { className: 'ose-events-note ose-events-error' }, topError) : null
          ),
          topHasMore ? h('div', { className: 'ose-pd-load-more-wrap' },
            h('button', {
              type: 'button',
              className: 'ose-pd-load-more',
              onClick: function () { setTopPage(topPage + 1); },
              disabled: topLoading
            }, topLoading ? 'Loading...' : 'Load More Comments')
          ) : null
        );
  }

  function GlobalFeedPage() {
    const communitiesRes = useApi('/openscene/v1/communities?limit=20');
    const communities = Array.isArray(communitiesRes.data)
      ? communitiesRes.data.map(function (c) {
          return {
            id: c.id,
            name: c.name,
            slug: c.slug,
            count: ''
          };
        })
      : [];

    return h('div', { className: 'ose-scene-home' },
      h(TopHeader),
      h('div', { className: 'ose-scene-grid' },
        h(LeftSidebar, { communities: communities }),
        h(CenterFeed, { communities: communities }),
        h(RightSidebar)
      )
    );
  }

  function SearchPage() {
    const searchParams = new URLSearchParams(window.location.search || '');
    const query = String(searchParams.get('q') || '').trim();
    const communitiesRes = useApi('/openscene/v1/communities?limit=100');
    const communities = Array.isArray(communitiesRes.data)
      ? communitiesRes.data.map(function (c) {
          return { id: c.id, name: c.name, slug: c.slug, count: '' };
        })
      : [];
    const [sortMode, setSortMode] = useState('hot');
    const [page, setPage] = useState(1);
    const searchPath = query.length >= 2
      ? '/openscene/v1/search?q=' + encodeURIComponent(query) + '&sort=' + sortMode + '&page=' + page + '&per_page=20'
      : null;
    const searchRes = useApi(searchPath);
    const rows = Array.isArray(searchRes.data) ? searchRes.data : [];
    const meta = searchRes.meta || {};
    const hasNext = rows.length === Number(meta.per_page || 20);
    const showPagination = query.length >= 2 && (page > 1 || hasNext);
    const [optimisticVotes, setOptimisticVotes] = useState({});
    const [deletedPosts, setDeletedPosts] = useState({});
    const [reportedPosts, setReportedPosts] = useState({});
    const communityMap = {};
    communities.forEach(function (c) { communityMap[c.id] = c.name; });

    useEffect(function () {
      setPage(1);
    }, [query, sortMode]);

    function loginRedirect() {
      const redirectTo = encodeURIComponent(window.location.href || '/openscene/');
      window.location.href = '/wp-login.php?redirect_to=' + redirectTo;
    }

    function handleVote(postId, clickedValue, currentVote) {
      if (Number(cfg.userId || 0) <= 0) {
        loginRedirect();
        return;
      }
      const effective = (currentVote === clickedValue) ? 0 : clickedValue;
      const delta = effective - currentVote;
      setOptimisticVotes(function (prev) {
        return Object.assign({}, prev, { [postId]: { user_vote: effective, delta: delta, pending: true } });
      });
      apiRequest({
        path: '/openscene/v1/posts/' + postId + '/vote',
        method: 'POST',
        data: { value: clickedValue }
      }).then(function (res) {
        const payload = res && res.data ? res.data : {};
        setOptimisticVotes(function (prev) {
          return Object.assign({}, prev, {
            [postId]: { user_vote: Number(payload.user_vote || 0), absoluteScore: Number(payload.score || 0), pending: false }
          });
        });
      }).catch(function () {
        setOptimisticVotes(function (prev) {
          const copy = Object.assign({}, prev);
          delete copy[postId];
          return copy;
        });
      });
    }

    function handleDelete(postId) {
      if (!postId || !window.confirm('Delete this post?')) return;
      apiRequest({ path: '/openscene/v1/posts/' + postId, method: 'DELETE' }).then(function () {
        setDeletedPosts(function (prev) {
          const copy = Object.assign({}, prev);
          copy[postId] = true;
          return copy;
        });
      }).catch(function () {});
    }

    function handleReport(postId) {
      if (!postId || reportedPosts[postId]) return;
      apiRequest({ path: '/openscene/v1/posts/' + postId + '/report', method: 'POST' }).then(function () {
        setReportedPosts(function (prev) {
          const copy = Object.assign({}, prev);
          copy[postId] = true;
          return copy;
        });
      }).catch(function () {});
    }

    return h('div', { className: 'ose-scene-home' },
      h(TopHeader),
      h('div', { className: 'ose-scene-grid' },
        h(LeftSidebar, { communities: communities }),
        h('main', { className: 'ose-center' },
          h('div', { className: 'ose-feed-header' },
            h('div', { className: 'ose-feed-tabs' },
              h('button', { className: sortMode === 'hot' ? 'is-active' : '', onClick: function () { setSortMode('hot'); } }, 'Hot'),
              h('button', { className: sortMode === 'new' ? 'is-active' : '', onClick: function () { setSortMode('new'); } }, 'New'),
              h('button', { className: sortMode === 'top' ? 'is-active' : '', onClick: function () { setSortMode('top'); } }, 'Top')
            )
          ),
          h('div', { className: 'ose-events-header' },
            h('h1', null, 'Results for "' + query + '"')
          ),
          query.length < 2 ? h('div', { className: 'ose-loading' }, 'Enter at least 2 characters to search.') : null,
          (query.length >= 2 && searchRes.loading) ? h('div', { className: 'ose-loading' }, 'Searching...') : null,
          (query.length >= 2 && searchRes.error) ? h('div', { className: 'ose-loading' }, searchRes.error) : null,
          (query.length >= 2 && !searchRes.loading && !searchRes.error && rows.length === 0) ? h('div', { className: 'ose-loading' }, 'No results found.') : null,
          h('div', { className: 'ose-feed-list' },
            rows.map(function (post) {
              const optimistic = optimisticVotes[post.id] || null;
              const mergedPost = Object.assign({}, post);
              if (optimistic) {
                mergedPost.user_vote = optimistic.user_vote;
                mergedPost.score = (typeof optimistic.absoluteScore === 'number')
                  ? optimistic.absoluteScore
                  : Number(post.score || 0) + Number(optimistic.delta || 0);
              }
              if (deletedPosts[post.id]) {
                mergedPost.status = 'removed';
                mergedPost.title = '[removed]';
                mergedPost.body = '';
                mergedPost.user_vote = 0;
              }
              if (reportedPosts[post.id]) {
                mergedPost.user_reported = true;
                mergedPost.reports_count = Number(mergedPost.reports_count || 0) + 1;
              }
              return h(FeedPost, {
                key: post.id,
                post: mergedPost,
                communityName: communityMap[post.community_id] || post.type || 'discussion',
                onVote: handleVote,
                onDelete: handleDelete,
                onReport: handleReport
              });
            })
          ),
          showPagination ? h('div', { className: 'ose-mod-pagination' },
            h('button', { type: 'button', onClick: function () { setPage(Math.max(1, page - 1)); }, disabled: page <= 1 || searchRes.loading }, 'Prev'),
            h('span', null, 'Page ' + page),
            h('button', { type: 'button', onClick: function () { setPage(page + 1); }, disabled: !hasNext || searchRes.loading }, 'Next')
          ) : null
        ),
        h(RightSidebar)
      )
    );
  }

  function CreatePostPage() {
    const communitiesRes = useApi('/openscene/v1/communities?limit=50');
    const communities = Array.isArray(communitiesRes.data)
      ? communitiesRes.data.filter(function (c) { return c && c.slug !== 'all-scenes'; })
      : [];

    const [form, setForm] = useState({
      community_id: '',
      type: 'text',
      title: '',
      body: '',
      event_date: '',
      event_end_date: '',
      venue_name: '',
      venue_address: '',
      ticket_url: '',
      anonymously: false
    });
    const [status, setStatus] = useState({ kind: '', message: '' });
    const [submitting, setSubmitting] = useState(false);

    function mapPostType(type) {
      if (type === 'link') return 'link';
      if (type === 'event') return 'event';
      return 'text';
    }

    function submitPost(e) {
      e.preventDefault();
      setStatus({ kind: '', message: '' });

      if (!form.community_id) {
        setStatus({ kind: 'error', message: 'Select a community.' });
        return;
      }
      if (!form.title.trim()) {
        setStatus({ kind: 'error', message: 'Title is required.' });
        return;
      }
      if (form.type === 'event') {
        if (!form.event_date) {
          setStatus({ kind: 'error', message: 'Event date is required for event posts.' });
          return;
        }
        if (!form.venue_name.trim()) {
          setStatus({ kind: 'error', message: 'Venue name is required for event posts.' });
          return;
        }
      }

      setSubmitting(true);
      const payload = {
        community_id: Number(form.community_id),
        type: mapPostType(form.type),
        title: form.title.trim(),
        body: form.body
      };

      if (form.type === 'event') {
        payload.event_date = localDateTimeToUtcString(form.event_date);
        payload.event_end_date = form.event_end_date ? localDateTimeToUtcString(form.event_end_date) : '';
        payload.venue_name = form.venue_name.trim();
        payload.venue_address = form.venue_address;
        payload.ticket_url = form.ticket_url.trim();
      }

      apiRequest({
        path: '/openscene/v1/posts',
        method: 'POST',
        data: payload
      }).then(function (res) {
        const id = res && res.data ? res.data.id : null;
        setSubmitting(false);
        setStatus({ kind: 'success', message: id ? ('Post published (#' + id + ').') : 'Post published.' });
        setForm({
          community_id: form.community_id,
          type: form.type,
          title: '',
          body: '',
          event_date: '',
          event_end_date: '',
          venue_name: '',
          venue_address: '',
          ticket_url: '',
          anonymously: false
        });
      }).catch(function (err) {
        setSubmitting(false);
        setStatus({ kind: 'error', message: err && err.message ? err.message : 'Publish failed.' });
      });
    }

    return h('div', { className: 'ose-create-page' },
      h(TopHeader),
      h('main', { className: 'ose-create-main' },
        h('div', { className: 'ose-create-layout' },
          h('section', { className: 'ose-create-left' },
            h('header', { className: 'ose-create-header' },
          h('h1', null, 'Create Post'),
          h('div', { className: 'ose-create-controls' },
            h('div', { className: 'ose-create-select-wrap' },
              h('select', {
                value: form.community_id,
                onChange: function (e) { setForm(Object.assign({}, form, { community_id: e.target.value })); }
              },
                h('option', { value: '' }, communitiesRes.loading ? 'Loading communities...' : 'Select Community'),
                communities.map(function (c) {
                  return h('option', { key: c.id, value: String(c.id) }, '/' + c.slug);
                })
              ),
              h('span', { className: 'ose-create-select-icon' }, Icon('chevron-down'))
            ),
            h('div', { className: 'ose-type-tabs' },
              ['text', 'link', 'event'].map(function (typeKey) {
                const active = form.type === typeKey;
                const label = typeKey.charAt(0).toUpperCase() + typeKey.slice(1);
                return h('button', {
                  key: typeKey,
                  type: 'button',
                  className: active ? 'is-active' : '',
                  onClick: function () { setForm(Object.assign({}, form, { type: typeKey })); }
                }, label);
              })
            )
          )
            ),
            h('form', { className: 'ose-create-form', onSubmit: submitPost },
          h('input', {
            className: 'ose-create-title',
            type: 'text',
            placeholder: 'Title*',
            value: form.title,
            onChange: function (e) { setForm(Object.assign({}, form, { title: e.target.value })); },
            required: true,
            maxLength: 300
          }),
          h('div', { className: 'ose-editor-wrap' },
            h('div', { className: 'ose-editor-hint' }, 'Markdown Support Active'),
            h('textarea', {
              className: 'ose-editor',
              placeholder: 'Share the sound, the scene, or the vibe...',
              value: form.body,
              onChange: function (e) { setForm(Object.assign({}, form, { body: e.target.value })); }
            }),
            h('div', { className: 'ose-editor-tools' },
              h('button', { type: 'button', title: 'Bold' }, Icon('bold')),
              h('button', { type: 'button', title: 'Italic' }, Icon('italic')),
              h('button', { type: 'button', title: 'Link' }, Icon('link')),
              h('button', { type: 'button', title: 'Image' }, Icon('image')),
              h('span', { className: 'ose-editor-divider' }),
              h('span', { className: 'ose-editor-count' }, 'CHARS: ' + form.body.length)
            )
          ),
          form.type === 'event' ? h('div', { className: 'ose-event-fields' },
            h('div', { className: 'ose-event-grid' },
              h('label', { className: 'ose-event-field' },
                h('span', null, 'Event Date'),
                h('input', {
                  type: 'datetime-local',
                  value: form.event_date,
                  onChange: function (e) { setForm(Object.assign({}, form, { event_date: e.target.value })); },
                  required: true
                })
              ),
              h('label', { className: 'ose-event-field' },
                h('span', null, 'End Date'),
                h('input', {
                  type: 'datetime-local',
                  value: form.event_end_date,
                  onChange: function (e) { setForm(Object.assign({}, form, { event_end_date: e.target.value })); }
                })
              )
            ),
            h('label', { className: 'ose-event-field' },
              h('span', null, 'Venue Name'),
              h('input', {
                type: 'text',
                value: form.venue_name,
                onChange: function (e) { setForm(Object.assign({}, form, { venue_name: e.target.value })); },
                required: true
              })
            ),
            h('label', { className: 'ose-event-field' },
              h('span', null, 'Venue Address'),
              h('textarea', {
                rows: 3,
                value: form.venue_address,
                onChange: function (e) { setForm(Object.assign({}, form, { venue_address: e.target.value })); }
              })
            ),
            h('label', { className: 'ose-event-field' },
              h('span', null, 'Ticket URL'),
              h('input', {
                type: 'url',
                value: form.ticket_url,
                onChange: function (e) { setForm(Object.assign({}, form, { ticket_url: e.target.value })); }
              })
            )
          ) : null,
          h('footer', { className: 'ose-create-footer' },
            h('div', { className: 'ose-create-footer-left' },
              h('button', { type: 'button', disabled: true }, Icon('save'), 'Save Draft'),
              h('button', {
                type: 'button',
                onClick: function () {
                  setForm(Object.assign({}, form, { title: '', body: '' }));
                  setStatus({ kind: '', message: '' });
                }
              }, 'Discard')
            ),
            h('div', { className: 'ose-create-footer-right' },
              h('label', { className: 'ose-anon' },
                h('input', {
                  type: 'checkbox',
                  checked: form.anonymously,
                  onChange: function (e) { setForm(Object.assign({}, form, { anonymously: !!e.target.checked })); }
                }),
                h('span', null, 'Post Anonymously')
              ),
              h('button', { className: 'ose-publish-btn', type: 'submit', disabled: submitting }, submitting ? 'Publishing...' : 'Publish Post')
            )
          ),
              status.message ? h('p', { className: 'ose-create-status ' + (status.kind === 'error' ? 'is-error' : 'is-success') }, status.message) : null
            )
          ),
          h('aside', { className: 'ose-create-right' }, h(SidebarRail))
        )
      ),
      h('div', { className: 'ose-create-accent ose-create-accent-top' }),
      h('div', { className: 'ose-create-accent ose-create-accent-bottom' })
    );
  }

  function UserProfilePage(props) {
    const username = String(props.username || '').trim();
    const [tab, setTab] = useState('posts');
    const [sort, setSort] = useState('latest');
    const [postLimit, setPostLimit] = useState(20);
    const [commentLimit, setCommentLimit] = useState(20);

    const activeLimit = tab === 'posts' ? postLimit : commentLimit;
    const activePath = '/openscene/v1/users/' + encodeURIComponent(username) + '/' + (tab === 'posts' ? 'posts' : 'comments') + '?per_page=' + activeLimit + '&offset=0';
    const activeRes = useApi(activePath);

    const posts = tab === 'posts' ? (Array.isArray(activeRes.data) ? activeRes.data : []) : [];
    const comments = tab === 'comments' ? (Array.isArray(activeRes.data) ? activeRes.data : []) : [];

    function sortRows(rows) {
      if (sort === 'latest') {
        return rows.slice();
      }
      return rows.slice().reverse();
    }

    const sortedPosts = sortRows(posts);
    const sortedComments = sortRows(comments);
    const activeRows = tab === 'posts' ? sortedPosts : sortedComments;
    const canLoadMore = tab === 'posts' ? posts.length >= postLimit : comments.length >= commentLimit;

    return h('section', { className: 'ose-user-hydration' },
        h('section', { className: 'ose-user-tabs' },
          h('button', { type: 'button', className: tab === 'posts' ? 'is-active' : '', onClick: function () { setTab('posts'); } }, 'Posts'),
          h('button', { type: 'button', className: tab === 'comments' ? 'is-active' : '', onClick: function () { setTab('comments'); } }, 'Comments')
        ),
        h('section', { className: 'ose-user-controls' },
          h('span', null, 'Latest Contributions'),
          h('button', {
            type: 'button',
            onClick: function () { setSort(sort === 'latest' ? 'oldest' : 'latest'); }
          }, 'Sort: ' + (sort === 'latest' ? 'Latest' : 'Oldest'), Icon('chevron-down'))
        ),
        h('section', { className: 'ose-user-list' },
          activeRows.map(function (row) {
            if (tab === 'posts') {
              return h('article', { className: 'ose-user-item', key: 'p-' + row.id },
                h('div', { className: 'ose-user-item-head' },
                  h('a', { href: '/post/' + row.id }, row.title || 'Untitled post'),
                  h('time', null, timeAgo(row.created_at))
                ),
                h('p', null, row.body || ''),
                h('div', { className: 'ose-user-item-foot' },
                  h('span', null, (row.type || 'discussion').toUpperCase()),
                  h('span', null, Icon('message-square'), String(row.comment_count || 0))
                )
              );
            }
            return h('article', { className: 'ose-user-item', key: 'c-' + row.id },
              h('div', { className: 'ose-user-item-head' },
                h('a', { href: '/post/' + row.post_id + '#comment-' + row.id }, 'Comment on thread #' + row.post_id),
                h('time', null, timeAgo(row.created_at))
              ),
              renderSafeHtml('p', 'ose-user-comment-body', row.body || ''),
              h('div', { className: 'ose-user-item-foot' },
                h('span', null, 'COMMENT'),
                h('span', null, Icon('arrow-up'), String(row.score || 0))
              )
            );
          })
        ),
        activeRes.loading ? h('p', { className: 'ose-events-note' }, 'Loading contributions...') : null,
        activeRes.error ? h('p', { className: 'ose-events-note ose-events-error' }, activeRes.error) : null,
        h('div', { className: 'ose-user-more-wrap' },
          h('button', {
            type: 'button',
            className: 'ose-user-more',
            onClick: function () {
              if (tab === 'posts') {
                setPostLimit(postLimit + 20);
              } else {
                setCommentLimit(commentLimit + 20);
              }
            },
            disabled: !canLoadMore
          }, 'View More', Icon('arrow-down'))
        )
    );
  }

  function ModeratorPanelPage() {
    const canModerate = !!(cfg && cfg.permissions && cfg.permissions.canModerate);
    const [view, setView] = useState('all');
    const [page, setPage] = useState(1);
    const [pending, setPending] = useState({});
    const modRes = useApi('/openscene/v1/moderation?view=' + encodeURIComponent(view) + '&page=' + page + '&per_page=20');
    const logsRes = useApi('/openscene/v1/moderation/logs?per_page=20&offset=0');

    useEffect(function () { setPage(1); }, [view]);

    if (!canModerate) {
      return h('div', { className: 'ose-post-detail-main' },
        h('h1', null, 'Forbidden'),
        h('p', null, 'You do not have permission to access the moderator panel.')
      );
    }

    const rows = Array.isArray(modRes.data) ? modRes.data : [];
    const logs = Array.isArray(logsRes.data) ? logsRes.data : [];

    function markPending(postId, value) {
      setPending(function (prev) {
        const copy = Object.assign({}, prev);
        copy[postId] = value;
        return copy;
      });
    }

    function actLock(row) {
      const nextLocked = Number(row.locked || 0) === 1 ? false : true;
      markPending(row.id, true);
      apiRequest({
        path: '/openscene/v1/posts/' + row.id + '/lock',
        method: 'POST',
        data: { locked: nextLocked }
      }).then(function () {
        markPending(row.id, false);
        setPage(1);
      }).catch(function () { markPending(row.id, false); });
    }

    function actPin(row) {
      const nextPinned = Number(row.pinned || 0) === 1 ? false : true;
      markPending(row.id, true);
      apiRequest({
        path: '/openscene/v1/posts/' + row.id + '/sticky',
        method: 'POST',
        data: { sticky: nextPinned }
      }).then(function () {
        markPending(row.id, false);
        setPage(1);
      }).catch(function () { markPending(row.id, false); });
    }

    function actRemove(row) {
      if (!window.confirm('Remove this post?')) return;
      markPending(row.id, true);
      apiRequest({
        path: '/openscene/v1/posts/' + row.id,
        method: 'DELETE'
      }).then(function () {
        markPending(row.id, false);
        setPage(1);
      }).catch(function () { markPending(row.id, false); });
    }

    function actClearReports(row) {
      markPending(row.id, true);
      apiRequest({
        path: '/openscene/v1/posts/' + row.id + '/clear-reports',
        method: 'POST'
      }).then(function () {
        markPending(row.id, false);
        setPage(1);
      }).catch(function () { markPending(row.id, false); });
    }

    function labelForView(v) {
      if (v === 'reported') return 'Reported';
      if (v === 'locked') return 'Locked';
      if (v === 'removed') return 'Removed';
      return 'All Threads';
    }

    return h('div', { className: 'ose-moderator-page' },
      h(TopHeader),
      h('main', { className: 'ose-mod-main' },
        h('aside', { className: 'ose-mod-left' },
          h('h3', null, 'View Management'),
          ['all', 'reported', 'locked', 'removed'].map(function (v) {
            return h('button', {
              key: v,
              type: 'button',
              className: view === v ? 'is-active' : '',
              onClick: function () { setView(v); }
            }, labelForView(v));
          })
        ),
        h('section', { className: 'ose-mod-center' },
          h('div', { className: 'ose-mod-head' },
            h('h2', null, 'Active Stream'),
            h('span', null, String(rows.length) + ' Threads')
          ),
          h('div', { className: 'ose-mod-list' },
            modRes.loading ? h('p', { className: 'ose-loading' }, 'Loading moderation queue...') : null,
            modRes.error ? h('p', { className: 'ose-events-note ose-events-error' }, modRes.error) : null,
            rows.map(function (row) {
              const isPending = !!pending[row.id];
              return h('article', { key: row.id, className: 'ose-mod-card' + (String(row.status || '') === 'removed' ? ' is-removed' : '') },
                h('div', { className: 'ose-mod-card-top' },
                  h('div', { className: 'ose-mod-badges' },
                    Number(row.reports_count || 0) > 0 ? h('span', { className: 'ose-mod-badge is-reported' }, String(row.reports_count) + ' Reports') : null,
                    Number(row.locked || 0) === 1 ? h('span', { className: 'ose-mod-badge' }, 'Locked') : null,
                    Number(row.pinned || 0) === 1 ? h('span', { className: 'ose-mod-badge' }, 'Pinned') : null,
                    String(row.status || '') === 'removed' ? h('span', { className: 'ose-mod-badge' }, 'Removed') : null
                  ),
                  h('small', null, 'Post #' + row.id)
                ),
                h('h3', null, row.title || '[removed]'),
                h('p', null, row.excerpt || ''),
                h('div', { className: 'ose-mod-meta' },
                  h('span', null, '@' + (row.username || 'user')),
                  h('span', null, (row.comment_count || 0) + ' comments')
                ),
                h('div', { className: 'ose-mod-actions' },
                  h('button', { type: 'button', onClick: function () { actLock(row); }, disabled: isPending || String(row.status || '') === 'removed' }, Number(row.locked || 0) === 1 ? 'Unlock' : 'Lock'),
                  h('button', { type: 'button', onClick: function () { actPin(row); }, disabled: isPending || String(row.status || '') === 'removed' }, Number(row.pinned || 0) === 1 ? 'Unpin' : 'Pin'),
                  h('button', { type: 'button', onClick: function () { actRemove(row); }, disabled: isPending || String(row.status || '') === 'removed' }, 'Remove'),
                  h('button', { type: 'button', onClick: function () { actClearReports(row); }, disabled: isPending || Number(row.reports_count || 0) <= 0 }, 'Clear Reports')
                )
              );
            })
          ),
          h('div', { className: 'ose-mod-pagination' },
            h('button', { type: 'button', onClick: function () { setPage(Math.max(1, page - 1)); }, disabled: page <= 1 }, 'Prev'),
            h('span', null, 'Page ' + page),
            h('button', { type: 'button', onClick: function () { setPage(page + 1); }, disabled: rows.length < 20 }, 'Next')
          )
        ),
        h('aside', { className: 'ose-mod-right' },
          h('h3', null, 'Activity History'),
          h('div', { className: 'ose-mod-log' },
            logs.map(function (log) {
              return h('article', { key: log.id, className: 'ose-mod-log-item' },
                h('p', null, (log.action || '') + ' post #' + (log.target_id || 0)),
                h('small', null, timeAgo(log.created_at))
              );
            })
          )
        )
      )
    );
  }

  function PlaceholderScreen(props) {
    return h('div', { className: 'ose-placeholder' },
      h(TopHeader),
      h('main', { className: 'ose-placeholder-main' },
        h('h1', null, props.title),
        h('p', null, 'This page baseline is active. Next pass will match its dedicated design export.')
      )
    );
  }

  function RouterShell() {
    useEffect(function () {
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
      initPostHeaderVote();
    });

    const contextRaw = (bootRoot.getAttribute('data-openscene-context') || '{}');
    let context = {};
    try {
      context = JSON.parse(contextRaw);
    } catch (e) {
      context = {};
    }

    const path = window.location.pathname || '';
    const view = new URLSearchParams(window.location.search).get('view');

    if ((context.route || '') === 'community' || path.indexOf('/c/') === 0) {
      const slugFromPath = path.indexOf('/c/') === 0 ? (path.split('/')[2] || '') : '';
      const communitySlug = context.communitySlug || slugFromPath;
      return h(CommunityHubPage, { communitySlug: communitySlug });
    }

    if ((context.route || '') === 'post' || path.indexOf('/post/') === 0) {
      const pathPart = path.indexOf('/post/') === 0 ? (path.split('/')[2] || '0') : '0';
      const postId = context.postId || Number(pathPart);
      const initialCommentCount = commentsRoot ? Number(commentsRoot.getAttribute('data-initial-comment-count') || '0') : 0;
      return h(PostDetailPage, { postId: postId, initialCommentCount: initialCommentCount });
    }

    if ((context.route || '') === 'user' || path.indexOf('/u/') === 0) {
      const userFromPath = path.indexOf('/u/') === 0 ? (path.split('/')[2] || '') : '';
      const usernameRaw = context.username || userFromPath;
      const username = String(usernameRaw).toLowerCase().replace(/[^a-z0-9_\-.]/g, '');
      return h(UserProfilePage, { username: username });
    }

    if ((context.route || '') === 'moderator' || path.indexOf('/moderator') === 0 || view === 'moderation') {
      return h(ModeratorPanelPage);
    }

    if ((context.route || '') === 'search' || path.indexOf('/search') === 0) {
      return h(SearchPage);
    }

    if (view === 'create') {
      return h(CreatePostPage);
    }

    if (view === 'events') {
      return h(EventsListPage);
    }

    if (view === 'communities') {
      return h(CommunitiesListPage);
    }

    if (view === 'event') {
      const eventId = new URLSearchParams(window.location.search).get('id') || '';
      return h(EventDetailPage, { eventId: eventId });
    }

    return h(GlobalFeedPage);
  }

  try {
    const mountTarget = (commentsRoot && (bootContext.route || '') === 'post')
      ? commentsRoot
      : ((userContentRoot && (bootContext.route || '') === 'user') ? userContentRoot : bootRoot);
    if (createRoot) {
      createRoot(mountTarget).render(h(RouterShell));
    } else if (legacyRender) {
      legacyRender(h(RouterShell), mountTarget);
    }
  } catch (e) {
    bootRoot.innerHTML = '<div style=\"padding:16px;color:#ff8a8a;font-family:system-ui,sans-serif\">OpenScene render error. Check browser console for details.</div>';
  }
})();
