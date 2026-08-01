/**
 * Inline client-side validation messages.
 * ---------------------------------------------------------------------------
 * E2E report §3.2 + §5.2.
 *
 * Filament marks required fields with the native HTML `required` attribute
 * (vendor/filament/forms/.../text-input.blade.php). That makes the BROWSER
 * block the submit before Livewire ever fires, so the server-side validator
 * never runs and Filament's own `<p class="fi-fo-field-wrp-error-message">` is
 * never rendered. All the user gets is a coloured border, a small icon, and —
 * for `type="email"` — an English native bubble. Colour-only signalling, and
 * inaccessible.
 *
 * This layer keeps the native constraints (they are the fastest possible
 * feedback, no round-trip) but takes over how they are reported:
 *
 *   • `invalid` is cancelled, which suppresses the browser bubble;
 *   • a localized message is rendered into the field's own wrapper, using the
 *     exact markup and classes Filament uses for server-side errors, so light
 *     and dark themes and RTL all come for free;
 *   • once a field has been touched, it re-validates on every keystroke —
 *     that is §5.2's "feedback as the user types", with zero server calls.
 *
 * Server-side messages still win: if Filament already rendered an error for a
 * field, we leave it alone. Ours are removed on submit and on SPA navigation.
 */
(function () {
    'use strict';

    var MARKER = 'data-client-validation-error';
    var TOUCHED = 'data-client-validation-touched';

    /**
     * Whether the current submit attempt has already moved focus. `invalid`
     * fires once per offending control, in document order, so this keeps the
     * user on the FIRST problem instead of being dragged to the last one.
     */
    var focusedThisAttempt = false;

    /** Localized strings injected by the panel render hook. */
    function strings() {
        return window.__etValidationMessages || {};
    }

    function t(key, fallback, replacements) {
        var value = strings()[key] || fallback;

        if (replacements) {
            Object.keys(replacements).forEach(function (token) {
                value = value.replace(':' + token, replacements[token]);
            });
        }

        return value;
    }

    /**
     * Only real, user-facing form controls inside a Filament field wrapper.
     * Hidden inputs, Livewire internals and controls outside a wrapper are
     * left to the framework.
     */
    function isCandidate(el) {
        if (!el || !el.willValidate) {
            return false;
        }

        if (el.type === 'hidden' || el.disabled || el.readOnly) {
            return false;
        }

        return !!el.closest('[data-field-wrapper]');
    }

    /**
     * The box Filament renders its own error message into: the inner grid that
     * holds the control, a direct grandchild of the wrapper. Falls back to the
     * wrapper so a markup change degrades to "message in the right area"
     * rather than "no message at all".
     */
    function messageHost(control) {
        var wrapper = control.closest('[data-field-wrapper]');

        if (!wrapper) {
            return null;
        }

        return wrapper.querySelector(':scope > div > div.grid') || wrapper;
    }

    /** A server-rendered Filament error already occupies this field. */
    function hasServerError(host) {
        return !!host.querySelector('.fi-fo-field-wrp-error-message:not([' + MARKER + '])');
    }

    /**
     * Translate the native ValidityState into a sentence in the app locale.
     * Ordered by how specific the flag is, so "please fill this in" never
     * masks "this is not a valid email".
     */
    function messageFor(control) {
        var v = control.validity;

        if (v.valid) {
            return null;
        }

        if (v.valueMissing) {
            return t('required', 'This field is required.');
        }

        if (v.typeMismatch) {
            if (control.type === 'email') {
                return t('email', 'Enter a valid email address.');
            }

            if (control.type === 'url') {
                return t('url', 'Enter a valid URL.');
            }

            return t('invalid', 'This value is not valid.');
        }

        if (v.patternMismatch) {
            return t('pattern', 'This value is not in the expected format.');
        }

        if (v.tooShort) {
            return t('min_length', 'Enter at least :min characters.', { min: control.minLength });
        }

        if (v.tooLong) {
            return t('max_length', 'Enter no more than :max characters.', { max: control.maxLength });
        }

        if (v.rangeUnderflow) {
            return t('min', 'The value must be at least :min.', { min: control.min });
        }

        if (v.rangeOverflow) {
            return t('max', 'The value must not exceed :max.', { max: control.max });
        }

        if (v.stepMismatch) {
            return t('step', 'This value is not an allowed increment.');
        }

        if (v.badInput) {
            return t('invalid', 'This value is not valid.');
        }

        return control.validationMessage || t('invalid', 'This value is not valid.');
    }

    function clear(control) {
        var host = messageHost(control);

        if (!host) {
            return;
        }

        var existing = host.querySelector('[' + MARKER + ']');

        if (existing) {
            existing.remove();
        }

        control.removeAttribute('aria-invalid');
    }

    function show(control, message) {
        var host = messageHost(control);

        if (!host || hasServerError(host)) {
            return;
        }

        var node = host.querySelector('[' + MARKER + ']');

        if (!node) {
            node = document.createElement('p');
            node.setAttribute(MARKER, '');
            node.setAttribute('data-validation-error', '');
            node.className = 'fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400';
            host.appendChild(node);
        }

        node.textContent = message;
        control.setAttribute('aria-invalid', 'true');
    }

    function refresh(control) {
        var message = messageFor(control);

        if (message) {
            show(control, message);
        } else {
            clear(control);
        }
    }

    function clearAll(root) {
        (root || document).querySelectorAll('[' + MARKER + ']').forEach(function (node) {
            node.remove();
        });
    }

    // --- Native `invalid` → our own message ---------------------------------
    // `invalid` does not bubble, hence the capture phase. preventDefault()
    // suppresses the browser bubble; the constraint itself still blocks submit.
    document.addEventListener(
        'invalid',
        function (event) {
            var control = event.target;

            if (!isCandidate(control)) {
                return;
            }

            event.preventDefault();
            control.setAttribute(TOUCHED, '');
            refresh(control);

            // Bring the first offending field into view. `block: 'center'`
            // rather than the default 'start', because the floating topbar
            // pill would otherwise sit on top of the field we just scrolled to.
            if (!focusedThisAttempt) {
                focusedThisAttempt = true;
                control.focus({ preventScroll: true });
                control.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        },
        true
    );

    // --- Live re-validation once a field has been touched -------------------
    document.addEventListener(
        'blur',
        function (event) {
            var control = event.target;

            if (!isCandidate(control)) {
                return;
            }

            // An untouched, still-empty field must not be scolded for being
            // empty just because the user tabbed through it.
            if (control.value === '' && !control.hasAttribute(TOUCHED)) {
                return;
            }

            control.setAttribute(TOUCHED, '');
            refresh(control);
        },
        true
    );

    document.addEventListener('input', function (event) {
        var control = event.target;

        if (!isCandidate(control) || !control.hasAttribute(TOUCHED)) {
            return;
        }

        refresh(control);
    });

    // --- Lifecycle ----------------------------------------------------------
    // A submit ATTEMPT starts at the button press, not at the `submit` event:
    // when a constraint fails the browser blocks `submit` entirely, so hooking
    // it would never reset anything on the path that matters. Clearing here
    // means the `invalid` handlers that fire a moment later re-render exactly
    // the fields that are still wrong, and nothing stale survives.
    document.addEventListener(
        'click',
        function (event) {
            var target = event.target;

            if (!target || typeof target.closest !== 'function') {
                return;
            }

            // `type === 'button'` skips Filament's own wire:click actions —
            // only a real submit trigger starts a validation attempt.
            var trigger = target.closest('button, [type="submit"]');

            if (!trigger || trigger.type === 'button') {
                return;
            }

            var form = trigger.closest('form');

            if (!form) {
                return;
            }

            focusedThisAttempt = false;
            clearAll(form);
        },
        true
    );

    // The success path: constraints passed, the form is really submitting.
    document.addEventListener(
        'submit',
        function (event) {
            focusedThisAttempt = false;
            clearAll(event.target);
        },
        true
    );

    // SPA navigation swaps the body; anything left behind would be stale.
    document.addEventListener('livewire:navigated', function () {
        focusedThisAttempt = false;
        clearAll();
    });
})();
