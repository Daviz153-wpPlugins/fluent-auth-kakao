(function () {
    'use strict';

    var REST_URL    = fakAdmin.restUrl;
    var NONCE       = fakAdmin.nonce;
    var REDIRECT_URI = fakAdmin.redirectUri;
    var settings    = { rest_api_key: '', client_secret: '' };
    var loaded      = false;

    fetch(REST_URL, { headers: { 'X-WP-Nonce': NONCE } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            settings = data;
            loaded   = true;
            populateCard();
        });

    function buildCard() {
        var card = document.createElement('div');
        card.id        = 'fak-kakao-card';
        card.className = 'fls_login_settings';
        card.innerHTML =
            '<h3 style="margin:0 0 15px;">카카오 설정으로 로그인</h3>' +

            '<div style="display:flex;align-items:center;gap:10px;margin-bottom:15px;">' +
                '<div id="fak-toggle" style="width:40px;height:20px;border-radius:10px;' +
                     'background:#dcdfe6;position:relative;cursor:pointer;transition:background .3s;flex-shrink:0;">' +
                    '<div id="fak-dot" style="position:absolute;top:2px;left:2px;width:16px;height:16px;' +
                         'background:#fff;border-radius:50%;transition:left .3s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></div>' +
                '</div>' +
                '<span>카카오로 로그인 활성화</span>' +
            '</div>' +

            '<div id="fak-fields" style="display:none;">' +
                '<div style="margin-bottom:12px;">' +
                    '<label style="display:block;font-weight:600;margin-bottom:5px;color:#606266;">REST API 키</label>' +
                    '<input id="fak-api-key" type="text" placeholder="카카오 앱 REST API 키"' +
                        ' style="width:100%;max-width:420px;padding:8px 12px;border:1px solid #dcdfe6;' +
                        'border-radius:4px;font-size:14px;color:#606266;">' +
                '</div>' +
                '<div style="margin-bottom:12px;">' +
                    '<label style="display:block;font-weight:600;margin-bottom:5px;color:#606266;">Client Secret</label>' +
                    '<input id="fak-client-secret" type="password" placeholder="카카오 앱 Client Secret (권장)"' +
                        ' style="width:100%;max-width:420px;padding:8px 12px;border:1px solid #dcdfe6;' +
                        'border-radius:4px;font-size:14px;color:#606266;">' +
                '</div>' +
                '<div style="margin-bottom:14px;color:#909399;font-size:13px;">' +
                    '리다이렉트 URI:&nbsp;' +
                    '<code id="fak-redirect-uri" style="background:#fff;border:1px solid #e4e7ed;padding:2px 6px;border-radius:3px;"></code>' +
                '</div>' +
                '<div>' +
                    '<button id="fak-save-btn" type="button"' +
                        ' style="background:#409eff;color:#fff;border:none;padding:8px 20px;' +
                        'border-radius:4px;cursor:pointer;font-size:14px;transition:background .2s;">' +
                        '카카오 설정 저장' +
                    '</button>' +
                    '<span id="fak-msg" style="margin-left:12px;font-size:13px;display:none;"></span>' +
                '</div>' +
            '</div>';

        return card;
    }

    function setToggle(on) {
        var toggle = document.getElementById('fak-toggle');
        var dot    = document.getElementById('fak-dot');
        var fields = document.getElementById('fak-fields');
        if (!toggle) return;
        toggle.style.background = on ? '#409eff' : '#dcdfe6';
        dot.style.left           = on ? '22px' : '2px';
        fields.style.display     = on ? 'block' : 'none';
    }

    function populateCard() {
        if (!loaded) return;
        var apiKey = document.getElementById('fak-api-key');
        var secret = document.getElementById('fak-client-secret');
        if (!apiKey) return;
        apiKey.value = settings.rest_api_key  || '';
        secret.value = settings.client_secret || '';
        setToggle(!!settings.rest_api_key);
    }

    function bindEvents() {
        var toggle = document.getElementById('fak-toggle');
        var btn    = document.getElementById('fak-save-btn');
        if (!toggle || toggle.dataset.bound) return;
        toggle.dataset.bound = '1';

        toggle.addEventListener('click', function () {
            var isOn = document.getElementById('fak-fields').style.display !== 'none';
            setToggle(!isOn);
        });

        btn.addEventListener('click', function () {
            var apiKey = document.getElementById('fak-api-key').value.trim();
            var secret = document.getElementById('fak-client-secret').value.trim();
            var msg    = document.getElementById('fak-msg');
            btn.disabled = true;

            fetch(REST_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify({ rest_api_key: apiKey, client_secret: secret }),
            })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                settings.rest_api_key  = apiKey;
                settings.client_secret = secret;
                setToggle(!!apiKey);
                msg.textContent    = data.message || '저장됨';
                msg.style.color    = '#67c23a';
                msg.style.display  = 'inline';
                setTimeout(function () { msg.style.display = 'none'; }, 3000);
            })
            .catch(function () {
                msg.textContent   = '저장 실패. 다시 시도해주세요.';
                msg.style.color   = '#f56c6c';
                msg.style.display = 'inline';
            })
            .finally(function () { btn.disabled = false; });
        });
    }

    function tryInject() {
        var socialCards = document.querySelectorAll('.fls_login_settings');
        if (socialCards.length === 0) return;

        if (document.getElementById('fak-kakao-card')) {
            populateCard();
            return;
        }

        var last = socialCards[socialCards.length - 1];
        var card = buildCard();
        last.parentNode.insertBefore(card, last.nextSibling);

        var redirectEl = card.querySelector('#fak-redirect-uri');
        if (redirectEl) redirectEl.textContent = REDIRECT_URI;

        populateCard();
        bindEvents();
    }

    var observer = new MutationObserver(tryInject);
    observer.observe(document.body, { childList: true, subtree: true });
})();
