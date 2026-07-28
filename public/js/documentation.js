/**
 * ElectroTech Orwa — دليل المنصة (public documentation page).
 *
 * Zero dependencies, no build step. Progressive enhancement only: every
 * feature here degrades to plain anchor navigation if JS is unavailable.
 *
 *   1. Theme (light / dark / system) with localStorage persistence
 *   2. Sidebar live search (filters groups + links)
 *   3. Active-section tracking + sidebar auto-scroll
 *   4. Collapsible sidebar groups
 *   5. Mobile drawer
 *   6. Reading-progress bar + back-to-top
 *   7. Copy-link anchors
 *   8. Keyboard shortcuts (/ or ctrl+k to search, esc to clear)
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'et-docs-theme';
    var root = document.documentElement;

    /* -----------------------------------------------------------------
       1. THEME
       ----------------------------------------------------------------- */

    var media = window.matchMedia('(prefers-color-scheme: dark)');

    function resolve(pref) {
        if (pref === 'dark' || pref === 'light') return pref;
        return media.matches ? 'dark' : 'light';
    }

    function storedPref() {
        try {
            return localStorage.getItem(STORAGE_KEY) || 'system';
        } catch (e) {
            return 'system';
        }
    }

    function applyTheme(pref) {
        var mode = resolve(pref);
        root.setAttribute('data-theme', mode);
        root.setAttribute('data-theme-pref', pref);

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', mode === 'dark' ? '#100e0c' : '#f2efe9');

        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            var label = mode === 'dark' ? 'التبديل إلى الوضع الفاتح' : 'التبديل إلى الوضع الداكن';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            btn.querySelectorAll('[data-theme-icon]').forEach(function (icon) {
                icon.hidden = icon.getAttribute('data-theme-icon') !== mode;
            });
        });
    }

    applyTheme(storedPref());

    if (media.addEventListener) {
        media.addEventListener('change', function () {
            if (storedPref() === 'system') applyTheme('system');
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (!toggle) return;

        var next = resolve(storedPref()) === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch (e) {
            /* private mode — the choice simply won't persist */
        }
        applyTheme(next);
    });

    /* -----------------------------------------------------------------
       2. SIDEBAR SEARCH
       ----------------------------------------------------------------- */

    var sidebar = document.querySelector('[data-sidebar]');
    var search = document.querySelector('[data-search]');
    var groups = sidebar ? Array.prototype.slice.call(sidebar.querySelectorAll('[data-group]')) : [];

    /** Arabic normalisation so "المشتريات" matches "مشتريات" and أ/إ/آ ≡ ا. */
    function normalize(value) {
        return (value || '')
            .toString()
            .toLowerCase()
            .replace(/[أإآٱ]/g, 'ا')
            .replace(/ى/g, 'ي')
            .replace(/ة/g, 'ه')
            .replace(/[ً-ْـ]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function runSearch(term) {
        if (!sidebar) return;

        var query = normalize(term);
        var anyVisible = false;

        groups.forEach(function (group) {
            var links = Array.prototype.slice.call(group.querySelectorAll('[data-keywords]'));
            var groupText = normalize(group.getAttribute('data-group-label') || '');
            var groupMatches = query !== '' && groupText.indexOf(query) !== -1;
            var visibleLinks = 0;

            links.forEach(function (link) {
                var haystack = normalize(link.getAttribute('data-keywords') + ' ' + link.textContent);
                var hit = query === '' || groupMatches || haystack.indexOf(query) !== -1;
                link.hidden = !hit;
                if (hit) visibleLinks++;
            });

            group.hidden = visibleLinks === 0;
            if (visibleLinks > 0) anyVisible = true;

            // While searching, force every matching group open so results
            // are never hidden behind a collapsed header.
            if (query !== '' && visibleLinks > 0) {
                group.setAttribute('data-open', 'true');
            }
        });

        sidebar.classList.toggle('is-filtered', query !== '');
        sidebar.classList.toggle('is-empty', query !== '' && !anyVisible);
    }

    if (search) {
        search.addEventListener('input', function () {
            runSearch(search.value);
        });

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                search.value = '';
                runSearch('');
                search.blur();
            }

            if (event.key === 'Enter') {
                var first = sidebar && sidebar.querySelector('[data-keywords]:not([hidden])');
                if (first) {
                    first.click();
                    search.blur();
                }
            }
        });
    }

    /* -----------------------------------------------------------------
       3. COLLAPSIBLE GROUPS
       ----------------------------------------------------------------- */

    document.addEventListener('click', function (event) {
        var head = event.target.closest('[data-group-toggle]');
        if (!head) return;

        var group = head.closest('[data-group]');
        if (!group) return;

        var open = group.getAttribute('data-open') !== 'false';
        group.setAttribute('data-open', open ? 'false' : 'true');
        head.setAttribute('aria-expanded', open ? 'false' : 'true');
    });

    /* -----------------------------------------------------------------
       4. ACTIVE SECTION TRACKING
       ----------------------------------------------------------------- */

    var links = sidebar ? Array.prototype.slice.call(sidebar.querySelectorAll('[data-jump]')) : [];
    var linkById = {};
    links.forEach(function (link) {
        linkById[link.getAttribute('data-jump')] = link;
    });

    var targets = Object.keys(linkById)
        .map(function (id) {
            return document.getElementById(id);
        })
        .filter(Boolean);

    var currentId = null;

    function setActive(id) {
        if (!id || id === currentId) return;
        currentId = id;

        links.forEach(function (link) {
            link.classList.remove('is-active');
        });

        var link = linkById[id];
        if (!link) return;

        link.classList.add('is-active');

        // Reveal the link's group and keep it in view inside the sidebar.
        var group = link.closest('[data-group]');
        if (group && group.getAttribute('data-open') === 'false' && !sidebar.classList.contains('is-filtered')) {
            group.setAttribute('data-open', 'true');
        }

        if (sidebar && link.offsetParent !== null) {
            var top = link.offsetTop;
            var view = sidebar.scrollTop;
            var height = sidebar.clientHeight;
            if (top < view + 60 || top > view + height - 80) {
                sidebar.scrollTo({ top: Math.max(0, top - height / 2), behavior: 'smooth' });
            }
        }
    }

    if ('IntersectionObserver' in window && targets.length) {
        var visible = new Map();

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        visible.set(entry.target.id, entry.boundingClientRect.top);
                    } else {
                        visible.delete(entry.target.id);
                    }
                });

                if (!visible.size) return;

                // The topmost intersecting section wins.
                var best = null;
                var bestTop = Infinity;
                visible.forEach(function (top, id) {
                    if (top < bestTop) {
                        bestTop = top;
                        best = id;
                    }
                });

                setActive(best);
            },
            {
                // Treat the band just under the topbar as "the reading line".
                rootMargin: '-' + (72 + 40) + 'px 0px -55% 0px',
                threshold: 0,
            }
        );

        targets.forEach(function (target) {
            observer.observe(target);
        });
    }

    /* -----------------------------------------------------------------
       5. MOBILE DRAWER
       ----------------------------------------------------------------- */

    function closeNav() {
        document.body.classList.remove('doc-nav-open');
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-nav-toggle]')) {
            document.body.classList.toggle('doc-nav-open');
            return;
        }

        if (event.target.closest('[data-nav-close]')) {
            closeNav();
            return;
        }

        // Any sidebar link closes the drawer on small screens.
        if (event.target.closest('[data-jump]') && window.innerWidth <= 900) {
            closeNav();
        }
    });

    /* -----------------------------------------------------------------
       6. PROGRESS BAR + BACK TO TOP
       ----------------------------------------------------------------- */

    var bar = document.querySelector('[data-progress]');
    var toTop = document.querySelector('[data-to-top]');
    var ticking = false;

    function onScroll() {
        if (ticking) return;
        ticking = true;

        window.requestAnimationFrame(function () {
            var scrolled = window.scrollY || document.documentElement.scrollTop;
            var height = document.documentElement.scrollHeight - window.innerHeight;
            var percent = height > 0 ? Math.min(100, (scrolled / height) * 100) : 0;

            if (bar) bar.style.width = percent + '%';
            if (toTop) toTop.classList.toggle('is-visible', scrolled > 700);

            ticking = false;
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    if (toTop) {
        toTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* -----------------------------------------------------------------
       7. COPY-LINK ANCHORS
       ----------------------------------------------------------------- */

    document.addEventListener('click', function (event) {
        var anchor = event.target.closest('[data-copy-link]');
        if (!anchor) return;

        event.preventDefault();

        var id = anchor.getAttribute('data-copy-link');
        var url = window.location.origin + window.location.pathname + '#' + id;

        history.replaceState(null, '', '#' + id);

        var done = function () {
            var original = anchor.textContent;
            anchor.textContent = '✓';
            setTimeout(function () {
                anchor.textContent = original;
            }, 1200);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, done);
        } else {
            done();
        }
    });

    /* -----------------------------------------------------------------
       8. KEYBOARD SHORTCUTS
       ----------------------------------------------------------------- */

    document.addEventListener('keydown', function (event) {
        var tag = (event.target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea' || event.target.isContentEditable;

        if ((event.key === '/' && !typing) || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k')) {
            event.preventDefault();
            if (search) {
                search.focus();
                search.select();
            }
            return;
        }

        if (event.key === 'Escape') {
            closeNav();
        }
    });

    /* -----------------------------------------------------------------
       9. DEEP LINK ON LOAD
       ----------------------------------------------------------------- */

    if (window.location.hash) {
        var target = document.getElementById(window.location.hash.slice(1));
        if (target) {
            // Let layout settle (web fonts) before correcting the offset.
            setTimeout(function () {
                target.scrollIntoView();
                setActive(target.id);
            }, 120);
        }
    }
})();
