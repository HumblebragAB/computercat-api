/**
 * Computer Cat — Easter Eggs
 */
(function() {
    'use strict';

    // Fosfor-paletten, samma värden som cc-theme.css.
    const CC = { bg: '#0d0b08', surface: '#15110a', text: '#efe6d2', amber: '#f2b544',
                 muted: '#a0824a', ok: '#9fd36b', danger: '#e8664a',
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
            ctx.fillStyle = 'rgba(13,11,8,0.08)';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = CC.amber;
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
            '20px 0px 0 #f2b544,24px -4px 0 #f2b544,28px 0px 0 #f2b544,' +
            '52px 0px 0 #f2b544,56px -4px 0 #f2b544,60px 0px 0 #f2b544,' +
            '24px 4px 0 #f2b544,28px 4px 0 #f2b544,32px 4px 0 #f2b544,' +
            '36px 4px 0 #f2b544,40px 4px 0 #f2b544,44px 4px 0 #f2b544,' +
            '48px 4px 0 #f2b544,52px 4px 0 #f2b544,56px 4px 0 #f2b544,' +
            '20px 8px 0 #f2b544,24px 8px 0 #f2b544,28px 8px 0 #f2b544,' +
            '32px 8px 0 #f2b544,36px 8px 0 #f2b544,40px 8px 0 #f2b544,' +
            '44px 8px 0 #f2b544,48px 8px 0 #f2b544,52px 8px 0 #f2b544,' +
            '56px 8px 0 #f2b544,60px 8px 0 #f2b544,' +
            '20px 12px 0 #f2b544,24px 12px 0 #f2b544,' +
            '28px 12px 0 #0d0b08,32px 12px 0 #efe6d2,' +
            '36px 12px 0 #f2b544,40px 12px 0 #f2b544,44px 12px 0 #f2b544,' +
            '48px 12px 0 #0d0b08,52px 12px 0 #efe6d2,' +
            '56px 12px 0 #f2b544,60px 12px 0 #f2b544,' +
            '20px 16px 0 #f2b544,24px 16px 0 #f2b544,28px 16px 0 #f2b544,' +
            '32px 16px 0 #f2b544,36px 16px 0 #f2b544,40px 16px 0 #e8664a,' +
            '44px 16px 0 #f2b544,48px 16px 0 #f2b544,52px 16px 0 #f2b544,' +
            '56px 16px 0 #f2b544,60px 16px 0 #f2b544,' +
            '8px 20px 0 #a0824a,12px 20px 0 #a0824a,' +
            '68px 20px 0 #a0824a,72px 20px 0 #a0824a,' +
            '20px 20px 0 #f2b544,24px 20px 0 #f2b544,28px 20px 0 #f2b544,' +
            '32px 20px 0 #f2b544,36px 20px 0 #f2b544,40px 20px 0 #f2b544,' +
            '44px 20px 0 #f2b544,48px 20px 0 #f2b544,52px 20px 0 #f2b544,' +
            '56px 20px 0 #f2b544,60px 20px 0 #f2b544,' +
            '24px 24px 0 #f2b544,28px 24px 0 #f2b544,32px 24px 0 #f2b544,' +
            '36px 24px 0 #f2b544,40px 24px 0 #f2b544,44px 24px 0 #f2b544,' +
            '48px 24px 0 #f2b544,52px 24px 0 #f2b544,56px 24px 0 #f2b544,' +
            '20px 28px 0 #f2b544,24px 28px 0 #f2b544,28px 28px 0 #f2b544,' +
            '32px 28px 0 #f2b544,36px 28px 0 #f2b544,40px 28px 0 #f2b544,' +
            '44px 28px 0 #f2b544,48px 28px 0 #f2b544,52px 28px 0 #f2b544,' +
            '56px 28px 0 #f2b544,60px 28px 0 #f2b544,' +
            '20px 32px 0 #f2b544,24px 32px 0 #f2b544,28px 32px 0 #f2b544,' +
            '32px 32px 0 #f2b544,36px 32px 0 #f2b544,40px 32px 0 #f2b544,' +
            '44px 32px 0 #f2b544,48px 32px 0 #f2b544,52px 32px 0 #f2b544,' +
            '56px 32px 0 #f2b544,60px 32px 0 #f2b544,' +
            '64px 28px 0 #f2b544,68px 28px 0 #f2b544,' +
            '72px 24px 0 #f2b544,76px 20px 0 #f2b544,80px 20px 0 #f2b544;';
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
            0%, 100% { box-shadow: 16px 36px 0 #f2b544, 20px 40px 0 #f2b544, 56px 36px 0 #f2b544, 60px 40px 0 #f2b544; }
            50% { box-shadow: 20px 36px 0 #f2b544, 16px 40px 0 #f2b544, 60px 36px 0 #f2b544, 56px 40px 0 #f2b544; }
        }
        .cc-egg-legs {
            box-shadow: 16px 36px 0 #f2b544, 20px 40px 0 #f2b544, 56px 36px 0 #f2b544, 60px 40px 0 #f2b544;
            animation: eggLegWalk 0.4s steps(1) infinite;
        }
        .cc-egg-legs.sitting {
            animation: none;
            box-shadow: 20px 36px 0 #f2b544, 24px 36px 0 #f2b544, 56px 36px 0 #f2b544, 60px 36px 0 #f2b544;
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
        speech.style.cssText = 'position:absolute;top:-26px;left:50%;transform:translateX(-50%);background:' + CC.surface + ';border:1px solid ' + CC.amber + ';color:' + CC.amber + ';padding:2px 8px;font-family:' + CC.font + ';font-size:11px;white-space:nowrap;border-radius:0;';
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
        const colors = ['#f2b544', '#f6c76e', '#e8a23a', '#d98e2b', '#efe6d2', '#a0824a'];
        let active = true;

        function trail(e) {
            if (!active) return;
            const dot = document.createElement('div');
            const color = colors[Math.floor(Math.random() * colors.length)];
            dot.style.cssText = `position:fixed;left:${e.clientX}px;top:${e.clientY}px;width:8px;height:8px;background:${color};pointer-events:none;z-index:99998;transition:all 0.8s;`;
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

    document.addEventListener('click', function(e) {
        const brand = e.target.closest('.fi-sidebar-header a, .fi-topbar a[href*="admin"], [class*="brand"], .fi-sidebar-header button');
        if (!brand) { brandClicks = 0; return; }

        brandClicks++;
        clearTimeout(brandTimer);
        brandTimer = setTimeout(() => brandClicks = 0, 2000);

        if (brandClicks >= 7) {
            brandClicks = 0;
            hackerMode();
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
            overlay.style.background = flashes % 2 === 0
                ? 'rgba(242,181,68,0.08)'
                : 'rgba(13,11,8,0.35)';
            document.body.style.transform = flashes % 2 === 0
                ? 'translateX(2px)' : 'translateX(-2px)';
            flashes++;
            if (flashes > 10) {
                clearInterval(glitchInterval);
                document.body.style.transform = '';
                overlay.style.background = 'transparent';

                // Show ACCESS GRANTED
                const msg = document.createElement('div');
                msg.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:99999;font-family:' + CC.font + ';font-weight:600;font-size:1.6rem;letter-spacing:.2em;color:' + CC.amber + ';background:' + CC.bg + ';border:1px solid ' + CC.amber + ';padding:1rem 1.5rem;text-align:center;pointer-events:none;';
                msg.innerHTML = '&gt; ACCESS_GRANTED<br><span style="font-size:.8rem;letter-spacing:.1em;color:' + CC.text + ';">welcome back, operator</span>';
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
        bubble.style.cssText = 'position:absolute;top:-26px;left:50%;transform:translateX(-50%);background:' + CC.surface + ';border:1px solid ' + CC.amber + ';color:' + CC.amber + ';padding:2px 8px;font-family:' + CC.font + ';font-size:11px;white-space:nowrap;border-radius:0;opacity:0;transition:opacity 0.3s;';
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
