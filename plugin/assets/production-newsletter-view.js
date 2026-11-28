(() => {
    'use strict';
    for (const picker of document.querySelectorAll('.sm-newsletter-picker')) {
        const trigger=picker.querySelector('.sm-newsletter-trigger');
        const menu=picker.querySelector('.sm-newsletter-menu');
        const label=trigger?.querySelector('[data-sm-country-label]');
        if (!trigger||!menu||!label) continue;
        const expanded=open=>{
            trigger.setAttribute('aria-expanded',String(open));
            menu.hidden=!open;
            if(open)menu.scrollTop=0;
        };
        trigger.addEventListener('click',()=>expanded(menu.hidden));
        menu.addEventListener('click',event=>{
            const choice=event.target.closest('[data-sm-country]');
            if(!choice||!menu.contains(choice))return;
            for(const option of menu.querySelectorAll('[data-sm-country]'))
                option.setAttribute('aria-pressed',String(option===choice));
            label.textContent=choice.dataset.smCountry;
            expanded(false);
            trigger.focus();
        });
        picker.addEventListener('keydown',event=>{
            if(event.key!=='Escape'||menu.hidden)return;
            event.preventDefault();
            expanded(false);
            trigger.focus();
        });
        document.addEventListener('click',event=>{
            if(!menu.hidden&&!picker.contains(event.target))expanded(false);
        });
    }
})();
