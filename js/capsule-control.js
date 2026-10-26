/**
 * Capsule segmented pill control.
 *
 * Keeps the active pill highlight, the mirrored hidden input, and the group's
 * data value in sync. Sends a `capsule:change` event so a page can immediately
 * filter its roster, grades, or academic records without a full page reload.
 */
(function () {
    'use strict';

    var ACTIVE_CLASS = 'rounded-full bg-slate-900 dark:bg-blue-600 text-white font-bold text-sm px-5 py-2 shadow-md transition-all';
    var INACTIVE_CLASS = 'rounded-full text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white font-semibold text-sm px-5 py-2 transition-all';

    function setValue(group, value, options) {
        if (!group) return;
        var nextValue = String(value);
        // Each group can own its pill metrics (for example the compact status
        // capsules); fall back to the shared grade-selector sizing.
        var activeClass = group.dataset.capsuleActiveClass || ACTIVE_CLASS;
        var inactiveClass = group.dataset.capsuleInactiveClass || INACTIVE_CLASS;
        group.dataset.capsuleValue = nextValue;

        group.querySelectorAll('[data-capsule-item]').forEach(function (item) {
            var active = item.dataset.value === nextValue;
            item.className = active ? activeClass : inactiveClass;
            item.setAttribute('aria-pressed', String(active));
            if (item.tagName === 'A') {
                if (active) item.setAttribute('aria-current', 'page');
                else item.removeAttribute('aria-current');
            }
        });

        var name = group.dataset.capsuleGroup || '';
        var input = group.querySelector('[data-capsule-input="' + (window.CSS && CSS.escape ? CSS.escape(name) : name) + '"]');
        if (input) input.value = nextValue;

        if (!options || options.silent !== true) {
            group.dispatchEvent(new CustomEvent('capsule:change', {
                bubbles: true,
                detail: { name: name, value: nextValue }
            }));
        }
    }

    document.querySelectorAll('[data-capsule-group]').forEach(function (group) {
        group.addEventListener('click', function (event) {
            var item = event.target.closest('[data-capsule-item]');
            if (!item || !group.contains(item)) return;

            var href = item.getAttribute('href') || item.dataset.href || '';
            event.preventDefault();
            setValue(group, item.dataset.value);

            if (href && href !== '#') window.location.assign(href);
        });
    });

    window.CapsuleControl = {
        /**
         * Update a capsule group by name.
         *
         * Pass { silent: true } when the caller will update the mirrored
         * select and dispatch its change event itself (for example the roster
         * "Clear filters" action). This avoids duplicate change events.
         */
        setValue: function (name, value, options) {
            setValue(document.querySelector('[data-capsule-group="' + name + '"]'), value, options);
        },
        getValue: function (name) {
            var group = document.querySelector('[data-capsule-group="' + name + '"]');
            return group ? group.dataset.capsuleValue : '';
        }
    };
})();
