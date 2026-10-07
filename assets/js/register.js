/* ========================================
   QAROTA — Registration form interactions
   - Auto-suggest username
   - Real-time availability check
   - Password strength indicator
   - Password visibility toggle
   ======================================== */

(function() {
    'use strict';

    const emailInput    = document.getElementById('email');
    const usernameInput = document.getElementById('username');
    const suggestBtn    = document.getElementById('suggestBtn');
    const passwordInput = document.getElementById('password');
    const toggleBtn     = document.getElementById('togglePassword');
    const toggleIcon    = document.getElementById('toggleIcon');
    const hint          = document.getElementById('usernameHint');
    const strengthFill  = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');
    const submitBtn     = document.getElementById('submitBtn');

    let userEdited     = false;
    let checkTimer     = null;
    let lastChecked    = '';
    let availableState = null;

    const labels = window.__registerLabels || {
        checking: 'Checking availability…',
        available: 'is available',
        taken: 'is taken',
        hint: 'Letters, numbers, dots, underscores. 3–30 characters.',
        weak: 'Weak',
        fair: 'Fair',
        good: 'Good',
        strong: 'Strong',
    };

    function setHint(text, state) {
        hint.textContent = text;
        hint.className = 'field-hint ' + (state ? 'hint-' + state : '');
    }

    /* ---- Username: auto-suggest from email ---- */
    usernameInput.addEventListener('input', function() {
        userEdited = true;
        scheduleCheck();
    });

    emailInput.addEventListener('blur', function() {
        if (!userEdited && emailInput.value.includes('@') && !usernameInput.value) {
            suggestFromEmail();
        }
    });

    suggestBtn.addEventListener('click', suggestFromEmail);

    function suggestFromEmail() {
        const email = emailInput.value.trim();
        if (!email || !email.includes('@')) {
            setHint('Enter an email first, then click Suggest.', 'warn');
            return;
        }

        let base = email.split('@')[0].toLowerCase();
        base = base.replace(/[^a-z0-9]+/g, '.').replace(/^\.+|\.+$/g, '').replace(/\.+/g, '.');

        if (base.length < 3) base = 'user.' + base;

        usernameInput.value = base;
        userEdited = true;
        checkAvailability(base);
    }

    /* ---- Username: debounced availability check ---- */
    function scheduleCheck() {
        clearTimeout(checkTimer);
        const value = usernameInput.value.trim();

        if (value === '') {
            setHint(labels.hint, '');
            availableState = null;
            return;
        }

        if (value === lastChecked) return;

        setHint(labels.checking, 'checking');
        availableState = null;

        checkTimer = setTimeout(function() {
            checkAvailability(value);
        }, 300);
    }

    function checkAvailability(username) {
        lastChecked = username;

        fetch('check_username.php?u=' + encodeURIComponent(username), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (usernameInput.value.trim() !== username) return;

            if (data.available) {
                availableState = true;
                setHint('✓ ' + username + ' ' + labels.available, 'ok');
            } else if (data.reason === 'invalid') {
                availableState = false;
                setHint('✗ ' + (data.message || 'Invalid username'), 'error');
            } else if (data.reason === 'taken') {
                availableState = false;
                const suggestion = data.suggestion ? ` — try "${data.suggestion}"` : '';
                setHint('✗ ' + username + ' ' + labels.taken + suggestion, 'error');
            } else {
                availableState = null;
                setHint(labels.hint, '');
            }
        })
        .catch(function() {
            availableState = null;
            setHint('Could not check availability. Try again.', 'warn');
        });
    }

    /* ---- Password: strength indicator ---- */
    passwordInput.addEventListener('input', function() {
        const val = passwordInput.value;
        if (val === '') {
            strengthFill.style.width = '0%';
            strengthFill.className = 'strength-fill';
            strengthLabel.textContent = '';
            return;
        }

        const score = passwordStrength(val);
        const levels = [
            { pct: '25%',  label: labels.weak,   cls: 'strength-weak'   },
            { pct: '50%',  label: labels.fair,   cls: 'strength-fair'   },
            { pct: '75%',  label: labels.good,   cls: 'strength-good'   },
            { pct: '100%', label: labels.strong, cls: 'strength-strong' },
        ];

        const level = levels[Math.min(score, 3)];
        strengthFill.style.width = level.pct;
        strengthFill.className = 'strength-fill ' + level.cls;
        strengthLabel.textContent = level.label;
        strengthLabel.className = 'strength-label ' + level.cls;
    });

    function passwordStrength(pw) {
        let score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        // Map 0–5 to 0–3
        if (score >= 5) return 3;
        if (score >= 4) return 2;
        if (score >= 3) return 1;
        if (score >= 2) return 0;
        return 0;
    }

    /* ---- Password: visibility toggle ---- */
    toggleBtn.addEventListener('click', function() {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        toggleIcon.textContent = isPassword ? '🙈' : '👁';
        toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });

    /* ---- Prevent submit if username is known-unavailable ---- */
    document.getElementById('registerForm').addEventListener('submit', function(e) {
        if (availableState === false) {
            e.preventDefault();
            setHint('✗ Please choose an available username.', 'error');
            usernameInput.focus();
        }
    });
})();