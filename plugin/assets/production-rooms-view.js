(() => {
    let sequence = 0;
    const initialize = layout => {
        const carousel = layout.querySelector('.sm-rooms-carousel');
        const cards = [...layout.querySelectorAll('.sm-rooms-category')];
        const dots = [...layout.querySelectorAll('[data-sm-room-dot]')];
        const slides = [...layout.querySelectorAll('.sm-rooms-slide')];
        const title = layout.querySelector('.sm-rooms-photo-title');
        const pause = layout.querySelector('[data-sm-room-pause]');
        if (!carousel || !title || !pause || cards.length < 2 || dots.length < 2 || slides.length !== dots.length) return;
        const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
        const desktop = matchMedia('(min-width: 1024px)');
        const widget = `sm-rooms-${++sequence}`;
        let category = 0;
        let photo = 0;
        let paused = reduceMotion.matches;
        let timer;

        const paint = () => {
            carousel.dataset.smRoomPhoto = String(photo);
            carousel.style.setProperty('--sm-room-offset', `${-100 * photo}%`);
            title.textContent = cards[category].querySelector('.sm-rooms-name').textContent.trim();
            dots.forEach((button, index) => button.setAttribute('aria-current', String(index === photo)));
            slides.forEach((slide, index) => slide.setAttribute('aria-hidden', String(index !== photo)));
            cards.forEach((card, index) => {
                const selected = index === category;
                card.dataset.smRoomActive = String(selected);
                card.querySelector('[data-sm-room-category]').setAttribute('aria-expanded', String(selected));
                card.querySelector('.sm-rooms-detail').hidden = !selected;
            });
            pause.setAttribute('aria-pressed', String(paused));
            pause.setAttribute('aria-label', paused ? pause.dataset.resumeLabel : pause.dataset.pauseLabel);
        };
        const schedule = () => {
            clearTimeout(timer);
            if (paused || !desktop.matches || document.hidden || carousel.matches(':hover') || carousel.contains(document.activeElement)) return;
            timer = setTimeout(() => {
                photo = (photo + 1) % dots.length;
                paint();
                schedule();
            }, 4000);
        };
        const showPhoto = next => {
            photo = (next + dots.length) % dots.length;
            paint();
            schedule();
        };
        cards.forEach((card, index) => {
            const control = card.querySelector('[data-sm-room-category]');
            const details = card.querySelector('.sm-rooms-detail');
            details.id ||= `${widget}-detail-${index}`;
            control.setAttribute('aria-controls', details.id);
            const select = () => {
                if (index !== category) {
                    category = index;
                    showPhoto(0);
                }
            };
            control.addEventListener('click', select);
            control.addEventListener('keydown', event => {
                if (event.key !== ' ' && event.key !== 'Enter') return;
                event.preventDefault();
                select();
            });
        });
        dots.forEach((dot, index) => dot.addEventListener('click', () => showPhoto(index)));
        layout.querySelector('[data-sm-room-previous]').addEventListener('click', () => showPhoto(photo - 1));
        layout.querySelector('[data-sm-room-next]').addEventListener('click', () => showPhoto(photo + 1));
        pause.addEventListener('click', () => { paused = !paused; paint(); schedule(); });
        carousel.addEventListener('mouseenter', schedule);
        carousel.addEventListener('mouseleave', schedule);
        carousel.addEventListener('focusin', schedule);
        carousel.addEventListener('focusout', () => setTimeout(schedule, 0));
        reduceMotion.addEventListener('change', () => { paused = reduceMotion.matches; paint(); schedule(); });
        desktop.addEventListener('change', schedule);
        document.addEventListener('visibilitychange', schedule);
        paint();
        schedule();
    };

    const mount = () => document.querySelectorAll('.sm-rooms-layout').forEach(initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
})();
