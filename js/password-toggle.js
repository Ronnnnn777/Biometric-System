document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[type="password"]').forEach((input, index) => {
        if (input.dataset.passwordToggleReady) return;
        input.dataset.passwordToggleReady = 'true';
        if (!input.id) input.id = `password-field-${index}`;
        const wrapper = document.createElement('div');
        wrapper.className = 'relative w-full';
        input.before(wrapper);
        wrapper.append(input);
        input.classList.add('pr-16');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'absolute right-2 top-1/2 z-10 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-bold text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500';
        const update = () => { const visible = input.type === 'text'; button.textContent = visible ? 'Hide' : 'Show'; button.setAttribute('aria-label', `${visible ? 'Hide' : 'Show'} password`); };
        button.addEventListener('click', () => { input.type = input.type === 'password' ? 'text' : 'password'; update(); input.focus(); });
        wrapper.append(button);
        update();
    });
});