/**
 * Computer Cat — Easter Eggs
 */
(function() {
    'use strict';

    // System 1-paletten (1-bit), samma värden som cc-theme.css.
    const CC = { ink: '#111111', paper: '#fbfaf6', desk: '#e9e6df', muted: '#55524b',
                 font: "'IBM Plex Mono', ui-monospace, monospace" };

    // -- 1. KONAMI CODE → Matrix cat rain --
    const KONAMI = [38,38,40,40,37,39,37,39,66,65];
    let konamiPos = 0;

    document.addEventListener('keydown', function(e) {
        if (e.keyCode === KONAMI[konamiPos]) {
            konamiPos++;
            if (konamiPos === KONAMI.length) {
                konamiPos = 0;
                catMatrix();
            }
        } else {
            konamiPos = 0;
        }

        // Track typed chars for "meow" detector
        typedChars += e.key;
        if (typedChars.length > 20) typedChars = typedChars.slice(-20);
        if (typedChars.toLowerCase().endsWith('meow')) {
            typedChars = '';
            summonCat();
        }
        if (typedChars.toLowerCase().endsWith('nyan')) {
            typedChars = '';
            nyanMode();
        }
    });

    function catMatrix() {
        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'position:fixed;inset:0;z-index:99999;pointer-events:none;';
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        document.body.appendChild(canvas);
        const ctx = canvas.getContext('2d');

        // En glyf per kolumn (16px breda), hämtade ur =^.^= /\\_/\\ och meow.
        const cats = '=^._/\\>< meow01'.replace(/ /g, '').split('');
        const fontSize = 16;
        const cols = Math.floor(canvas.width / fontSize);
        const drops = Array(cols).fill(0);

        let frames = 0;
        function draw() {
            ctx.fillStyle = 'rgba(251,250,246,0.12)';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = CC.ink;
            ctx.font = fontSize + 'px ' + CC.font;

            for (let i = 0; i < cols; i++) {
                const char = cats[Math.floor(Math.random() * cats.length)];
                ctx.fillText(char, i * fontSize, drops[i] * fontSize);
                if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) {
                    drops[i] = 0;
                }
                drops[i]++;
            }

            frames++;
            if (frames < 200) {
                requestAnimationFrame(draw);
            } else {
                canvas.style.transition = 'opacity 1s';
                canvas.style.opacity = '0';
                setTimeout(() => canvas.remove(), 1000);
            }
        }
        draw();
    }

    // -- 2. TYPE "MEOW" → Pixel cat walks across screen --
    let typedChars = '';

    function createPixelCat() {
        const cat = document.createElement('div');
        cat.style.cssText = 'position:relative;width:80px;height:48px;image-rendering:pixelated;';

        const body = document.createElement('div');
        body.style.cssText = 'width:4px;height:4px;position:absolute;top:0;left:0;background:transparent;box-shadow:' +
            '20px 0px 0 #111111,24px -4px 0 #111111,28px 0px 0 #111111,' +
            '52px 0px 0 #111111,56px -4px 0 #111111,60px 0px 0 #111111,' +
            '24px 4px 0 #111111,28px 4px 0 #111111,32px 4px 0 #111111,' +
            '36px 4px 0 #111111,40px 4px 0 #111111,44px 4px 0 #111111,' +
            '48px 4px 0 #111111,52px 4px 0 #111111,56px 4px 0 #111111,' +
            '20px 8px 0 #111111,24px 8px 0 #111111,28px 8px 0 #111111,' +
            '32px 8px 0 #111111,36px 8px 0 #111111,40px 8px 0 #111111,' +
            '44px 8px 0 #111111,48px 8px 0 #111111,52px 8px 0 #111111,' +
            '56px 8px 0 #111111,60px 8px 0 #111111,' +
            '20px 12px 0 #111111,24px 12px 0 #111111,' +
            '28px 12px 0 #fbfaf6,32px 12px 0 #111111,' +
            '36px 12px 0 #111111,40px 12px 0 #111111,44px 12px 0 #111111,' +
            '48px 12px 0 #fbfaf6,52px 12px 0 #111111,' +
            '56px 12px 0 #111111,60px 12px 0 #111111,' +
            '20px 16px 0 #111111,24px 16px 0 #111111,28px 16px 0 #111111,' +
            '32px 16px 0 #111111,36px 16px 0 #111111,40px 16px 0 #fbfaf6,' +
            '44px 16px 0 #111111,48px 16px 0 #111111,52px 16px 0 #111111,' +
            '56px 16px 0 #111111,60px 16px 0 #111111,' +
            '8px 20px 0 #55524b,12px 20px 0 #55524b,' +
            '68px 20px 0 #55524b,72px 20px 0 #55524b,' +
            '20px 20px 0 #111111,24px 20px 0 #111111,28px 20px 0 #111111,' +
            '32px 20px 0 #111111,36px 20px 0 #111111,40px 20px 0 #111111,' +
            '44px 20px 0 #111111,48px 20px 0 #111111,52px 20px 0 #111111,' +
            '56px 20px 0 #111111,60px 20px 0 #111111,' +
            '24px 24px 0 #111111,28px 24px 0 #111111,32px 24px 0 #111111,' +
            '36px 24px 0 #111111,40px 24px 0 #111111,44px 24px 0 #111111,' +
            '48px 24px 0 #111111,52px 24px 0 #111111,56px 24px 0 #111111,' +
            '20px 28px 0 #111111,24px 28px 0 #111111,28px 28px 0 #111111,' +
            '32px 28px 0 #111111,36px 28px 0 #111111,40px 28px 0 #111111,' +
            '44px 28px 0 #111111,48px 28px 0 #111111,52px 28px 0 #111111,' +
            '56px 28px 0 #111111,60px 28px 0 #111111,' +
            '20px 32px 0 #111111,24px 32px 0 #111111,28px 32px 0 #111111,' +
            '32px 32px 0 #111111,36px 32px 0 #111111,40px 32px 0 #111111,' +
            '44px 32px 0 #111111,48px 32px 0 #111111,52px 32px 0 #111111,' +
            '56px 32px 0 #111111,60px 32px 0 #111111,' +
            '64px 28px 0 #111111,68px 28px 0 #111111,' +
            '72px 24px 0 #111111,76px 20px 0 #111111,80px 20px 0 #111111;';
        cat.appendChild(body);

        const legs = document.createElement('div');
        legs.style.cssText = 'width:4px;height:4px;position:absolute;top:0;left:0;background:transparent;';
        legs.className = 'cc-egg-legs';
        cat.appendChild(legs);

        return cat;
    }

    // Inject walking animation for egg cats
    const eggStyle = document.createElement('style');
    eggStyle.textContent = `
        @keyframes eggLegWalk {
            0%, 100% { box-shadow: 16px 36px 0 #111111, 20px 40px 0 #111111, 56px 36px 0 #111111, 60px 40px 0 #111111; }
            50% { box-shadow: 20px 36px 0 #111111, 16px 40px 0 #111111, 60px 36px 0 #111111, 56px 40px 0 #111111; }
        }
        .cc-egg-legs {
            box-shadow: 16px 36px 0 #111111, 20px 40px 0 #111111, 56px 36px 0 #111111, 60px 40px 0 #111111;
            animation: eggLegWalk 0.4s steps(1) infinite;
        }
        .cc-egg-legs.sitting {
            animation: none;
            box-shadow: 20px 36px 0 #111111, 24px 36px 0 #111111, 56px 36px 0 #111111, 60px 36px 0 #111111;
        }
    `;
    document.head.appendChild(eggStyle);

    function summonCat() {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'position:fixed;bottom:0;z-index:99999;pointer-events:none;transition:none;';
        const fromLeft = Math.random() > 0.5;
        wrapper.style[fromLeft ? 'left' : 'right'] = '-90px';

        const cat = createPixelCat();
        if (!fromLeft) cat.style.transform = 'scaleX(-1)';
        wrapper.appendChild(cat);

        const speech = document.createElement('div');
        speech.textContent = 'Meow!';
        speech.style.cssText = 'position:absolute;top:-26px;left:50%;transform:translateX(-50%);background:' + CC.paper + ';border:2px solid ' + CC.ink + ';color:' + CC.ink + ';padding:2px 8px;font-family:' + CC.font + ';font-size:11px;white-space:nowrap;border-radius:0;';
        wrapper.appendChild(speech);

        document.body.appendChild(wrapper);

        let pos = -90;
        const speed = 2;
        const target = window.innerWidth + 100;

        function step() {
            pos += speed;
            wrapper.style[fromLeft ? 'left' : 'right'] = pos + 'px';
            if (pos < target) {
                requestAnimationFrame(step);
            } else {
                wrapper.remove();
            }
        }
        requestAnimationFrame(step);
    }

    // -- 3. TYPE "NYAN" → Rainbow trail on cursor for 10 seconds --
    function nyanMode() {
        const colors = ['#111111', '#55524b'];
        let active = true;

        function trail(e) {
            if (!active) return;
            const dot = document.createElement('div');
            const color = colors[Math.floor(Math.random() * colors.length)];
            dot.style.cssText = `position:fixed;left:${e.clientX}px;top:${e.clientY}px;width:8px;height:8px;box-sizing:border-box;border:2px solid ${color};background:${Math.random() > .5 ? color : 'transparent'};pointer-events:none;z-index:99998;transition:all 0.8s;`;
            document.body.appendChild(dot);
            requestAnimationFrame(() => {
                dot.style.opacity = '0';
                dot.style.transform = 'scale(0) translateY(20px)';
            });
            setTimeout(() => dot.remove(), 800);
        }

        document.addEventListener('mousemove', trail);
        setTimeout(() => {
            active = false;
            document.removeEventListener('mousemove', trail);
        }, 10000);
    }

    // -- 4. CLICK BRAND 7 TIMES → Hacker mode glitch --
    let brandClicks = 0;
    let brandTimer = null;

    let brandNav = null;

    document.addEventListener('click', function(e) {
        const brand = e.target.closest('.fi-sidebar-header a, .fi-topbar a[href*="admin"], [class*="brand"], .fi-sidebar-header button');
        if (!brand) { brandClicks = 0; return; }

        // Varumärket är en länk som laddar om sidan, och då nollställs räknaren.
        // Vanliga klick fördröjs därför 350 ms; kommer fler klick blir det ingen
        // navigering. Cmd/ctrl/shift/mittenklick (ny flik) lämnas orörda.
        const isPlainLinkClick = brand.tagName === 'A' && brand.href && e.button === 0
            && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;
        if (isPlainLinkClick) {
            e.preventDefault();
            clearTimeout(brandNav);
        }

        brandClicks++;
        clearTimeout(brandTimer);
        brandTimer = setTimeout(() => brandClicks = 0, 2000);

        if (brandClicks >= 7) {
            brandClicks = 0;
            hackerMode();
        } else if (isPlainLinkClick) {
            brandNav = setTimeout(() => { window.location.href = brand.href; }, 350);
        }
    });

    function hackerMode() {
        // Glitch effect
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;pointer-events:none;';
        document.body.appendChild(overlay);

        // Rapid glitch flashes
        let flashes = 0;
        const glitchInterval = setInterval(() => {
            // Svag rasterflimmer i stället för helskärmsinvertering: låg luminansändring (WCAG 2.3.1).
            overlay.style.background = flashes % 2 === 0
                ? 'repeating-linear-gradient(0deg, rgba(17,17,17,0.08) 0 1px, transparent 1px 3px)'
                : 'transparent';
            document.body.style.transform = flashes % 2 === 0
                ? 'translateX(2px)' : 'translateX(-2px)';
            flashes++;
            if (flashes > 10) {
                clearInterval(glitchInterval);
                document.body.style.transform = '';
                overlay.style.background = 'transparent';

                // Show ACCESS GRANTED
                const msg = document.createElement('div');
                msg.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:99999;font-family:' + CC.font + ';font-weight:600;font-size:1.2rem;letter-spacing:.08em;color:' + CC.ink + ';background:' + CC.paper + ';border:2px solid ' + CC.ink + ';box-shadow:4px 4px 0 ' + CC.ink + ';padding:1.25rem 1.75rem;text-align:center;pointer-events:none;';
                msg.innerHTML = '&#9888;&#xFE0E; ACCESS_GRANTED<br><span style="font-size:.8rem;letter-spacing:.04em;font-weight:400;color:' + CC.muted + ';">welcome back, operator</span>';
                document.body.appendChild(msg);

                setTimeout(() => {
                    msg.style.transition = 'opacity 1s';
                    msg.style.opacity = '0';
                    setTimeout(() => { msg.remove(); overlay.remove(); }, 1000);
                }, 2000);
            }
        }, 80);
    }

    // -- 5. IDLE CAT → After 60s of no interaction, cat peeks from sidebar --
    let idleTimer = null;
    let idleCatShown = false;

    function resetIdle() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(showIdleCat, 60000);
    }

    function showIdleCat() {
        if (idleCatShown) return;
        idleCatShown = true;

        const peek = document.createElement('div');
        peek.style.cssText = 'position:fixed;bottom:-50px;right:20px;z-index:99999;cursor:pointer;transition:bottom 0.5s cubic-bezier(0.34,1.56,0.64,1);';

        const cat = createPixelCat();
        cat.querySelector('.cc-egg-legs').classList.add('sitting');
        peek.appendChild(cat);

        document.body.appendChild(peek);
        setTimeout(() => peek.style.bottom = '-20px', 100);

        const bubble = document.createElement('div');
        bubble.textContent = 'Still there?';
        bubble.style.cssText = 'position:absolute;top:-26px;left:50%;transform:translateX(-50%);background:' + CC.paper + ';border:2px solid ' + CC.ink + ';color:' + CC.ink + ';padding:2px 8px;font-family:' + CC.font + ';font-size:11px;white-space:nowrap;border-radius:0;opacity:0;transition:opacity 0.3s;';
        peek.appendChild(bubble);
        setTimeout(() => bubble.style.opacity = '1', 600);

        peek.addEventListener('click', function() {
            bubble.textContent = '*purr*';
            setTimeout(() => {
                peek.style.bottom = '-60px';
                setTimeout(() => { peek.remove(); idleCatShown = false; resetIdle(); }, 500);
            }, 1000);
        });

        // Auto-hide after 10s
        setTimeout(() => {
            if (peek.parentNode) {
                peek.style.bottom = '-60px';
                setTimeout(() => { peek.remove(); idleCatShown = false; resetIdle(); }, 500);
            }
        }, 10000);
    }

    ['mousemove','keydown','click','scroll'].forEach(e => document.addEventListener(e, function() {
        idleCatShown = false;
        resetIdle();
    }));
    resetIdle();

})();
