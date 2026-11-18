// Live password-policy feedback, shared by install and settings forms.
// Server-side validation is authoritative.
document.addEventListener('DOMContentLoaded', function () {
    var password = document.getElementById('password');
    var verifyPassword = document.getElementById('verifyPassword');
    var message = document.getElementById('passwordMessage');

    if (!password || !message) {
        return;
    }

    function validatePassword() {
        var value = password.value;
        var messages = [];

        if (Array.from(value).length < 8) {
            messages.push('at least 8 characters');
        }
        if (!/\p{Ll}/u.test(value)) {
            messages.push('one lowercase letter');
        }
        if (!/\p{Lu}/u.test(value)) {
            messages.push('one uppercase letter');
        }
        if (!/\d/.test(value)) {
            messages.push('one number');
        }
        if (!/[^\p{L}\p{N}]/u.test(value)) {
            messages.push('one non-letter/non-number character');
        }

        if (messages.length > 0) {
            message.innerHTML = 'Password must include ' + messages.join(', ') + '.';
            message.style.color = '#ff6347';
        } else {
            message.innerHTML = 'Password meets all requirements.';
            message.style.color = '#4caf82';
        }

        if (verifyPassword) {
            if (password.value === verifyPassword.value && password.value.length > 0) {
                message.innerHTML += '<br>Passwords match.';
            } else if (verifyPassword.value.length > 0) {
                message.innerHTML += '<br>Passwords do not match.';
                message.style.color = '#ff6347';
            }
        }
    }

    password.addEventListener('input', validatePassword);
    if (verifyPassword) {
        verifyPassword.addEventListener('input', validatePassword);
    }
});
