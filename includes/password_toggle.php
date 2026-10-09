<script>
document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
        var input = button.closest('.input-group').querySelector('input');
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.title = show ? 'Hide password' : 'Show password';
        button.querySelector('span').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
});
</script>
