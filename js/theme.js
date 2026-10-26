/**
 * Colour theme preference.
 *
 * The stored choice is applied as early as possible (this script is loaded
 * synchronously in <head>) so the portal never flashes the wrong theme.
 * Supported modes: "light", "dark", and "system".
 */
(function () {
    'use strict';

    var storageKey = 'biometric-theme';
    var root = document.documentElement;
    var media = window.matchMedia('(prefers-color-scheme: dark)');

    function readMode() {
        try {
            var saved = window.localStorage.getItem(storageKey);
            return saved === 'light' || saved === 'dark' ? saved : 'system';
        } catch (error) {
            return 'system';
        }
    }

    // Kept in memory so the picker still reflects the active theme when
    // localStorage is blocked (for example in private browsing).
    var currentMode = readMode();

    function resolveMode(mode) {
        return mode === 'system' ? (media.matches ? 'dark' : 'light') : mode;
    }

    function applyMode(mode) {
        var theme = resolveMode(mode);
        root.setAttribute('data-theme-mode', mode);
        root.setAttribute('data-theme', theme);
        root.style.colorScheme = theme;
    }

    function syncButtons() {
        var mode = currentMode;
        var buttons = document.querySelectorAll('[data-theme-option]');

        Array.prototype.forEach.call(buttons, function (button) {
            var active = button.getAttribute('data-theme-option') === mode;
            button.setAttribute('aria-pressed', String(active));
            button.classList.toggle('theme-option-active', active);
        });
    }

    function wireButtons() {
        var buttons = document.querySelectorAll('[data-theme-option]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var mode = button.getAttribute('data-theme-option');
                if (mode !== 'light' && mode !== 'dark' && mode !== 'system') {
                    return;
                }
                currentMode = mode;
                try {
                    window.localStorage.setItem(storageKey, mode);
                } catch (error) {
                    // Private browsing may block storage; the theme still
                    // applies for the current page.
                }
                applyMode(mode);
                syncButtons();
            });
        });

        syncButtons();
    }

    applyMode(currentMode);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireButtons);
    } else {
        wireButtons();
    }

    var handleSystemChange = function () {
        if (currentMode === 'system') {
            applyMode('system');
        }
    };

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', handleSystemChange);
    } else if (typeof media.addListener === 'function') {
        media.addListener(handleSystemChange);
    }

    window.addEventListener('storage', function (event) {
        if (event.key !== storageKey) {
            return;
        }
        currentMode = readMode();
        applyMode(currentMode);
        syncButtons();
    });
})();
