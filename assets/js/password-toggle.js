/**
 * Global Password Visibility Toggle
 * Enhances every password field and any future .password-toggle-field input.
 */
(function () {
    const FIELD_SELECTOR = 'input[type="password"], input.password-toggle-field, input[data-password-toggle]';
    const SPACING_CLASS_PATTERN = /^(m[trblxy]?)-(?:0|1|2|3|4|5|auto)$/;

    function setToggleState(field, button, visible) {
        const icon = button.querySelector('i');

        field.type = visible ? 'text' : 'password';
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.title = visible ? 'Hide password' : 'Show password';

        icon.classList.toggle('bi-eye', !visible);
        icon.classList.toggle('bi-eye-slash', visible);
    }

    function moveSpacingClasses(field, wrap) {
        Array.from(field.classList).forEach(function (className) {
            if (SPACING_CLASS_PATTERN.test(className)) {
                field.classList.remove(className);
                wrap.classList.add(className);
            }
        });
    }

    function enhancePasswordField(field) {
        if (!field || field.dataset.passwordToggleReady === 'true') {
            return;
        }

        field.classList.add('password-toggle-field');
        field.dataset.passwordToggleReady = 'true';

        let wrap = field.closest('.password-field-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'password-field-wrap';
            field.parentNode.insertBefore(wrap, field);
            wrap.appendChild(field);
        }

        moveSpacingClasses(field, wrap);

        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'password-visibility-toggle';
        toggleBtn.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';

        wrap.appendChild(toggleBtn);
        setToggleState(field, toggleBtn, field.type === 'text');

        toggleBtn.addEventListener('click', function () {
            setToggleState(field, toggleBtn, field.type === 'password');
            field.focus({ preventScroll: true });
        });
    }

    function enhancePasswordFields(root) {
        const scope = root || document;

        if (scope.matches && scope.matches(FIELD_SELECTOR)) {
            enhancePasswordField(scope);
        }

        scope.querySelectorAll(FIELD_SELECTOR).forEach(enhancePasswordField);
    }

    document.addEventListener('DOMContentLoaded', function () {
        enhancePasswordFields(document);

        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        enhancePasswordFields(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
