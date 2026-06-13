(function () {
    'use strict';

    var REST_URL     = fakAdmin.restUrl;
    var NONCE        = fakAdmin.nonce;
    var REDIRECT_URI = fakAdmin.redirectUri;
    var settings     = { rest_api_key: '', client_secret: '', key_method: 'db', hide_email_login: 'no' };
    var loaded       = false;

    function makeEyeIcon(visible) {
        return visible
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    }

    function bindEyeToggle(inputId, btnId) {
        var input = document.getElementById(inputId);
        var btn   = document.getElementById(btnId);
        if (!input || !btn) return;
        btn.addEventListener('click', function () {
            var show  = input.type === 'password';
            input.type    = show ? 'text' : 'password';
            btn.innerHTML = makeEyeIcon(show);
        });
    }

    fetch(REST_URL, { headers: { 'X-WP-Nonce': NONCE } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            settings = data;
            loaded   = true;
            populateCard();
        });

    var hideEmailOn = false; // 토글 상태를 색상 비교 대신 명시적으로 추적

    var TAB_ON   = 'background:#1f1f25;color:#fff;border:1px solid #1f1f25;font-weight:600;';
    var TAB_OFF  = 'background:#fff;color:#606266;border:1px solid #dcdfe6;font-weight:400;';
    var TAB_BASE = 'padding:5px 16px;border-radius:3px;cursor:pointer;font-size:13px;margin-right:6px;';

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

                '<div style="margin-bottom:14px;">' +
                    '<label style="display:block;font-weight:600;margin-bottom:8px;color:#606266;">자격 증명 저장 방법</label>' +
                    '<div>' +
                        '<button type="button" id="fak-method-db"' +
                            ' style="' + TAB_BASE + TAB_ON + '">데이터베이스</button>' +
                        '<button type="button" id="fak-method-wpconfig"' +
                            ' style="' + TAB_BASE + TAB_OFF + '">wp-config</button>' +
                    '</div>' +
                '</div>' +

                '<div id="fak-section-db">' +
                    '<div style="margin-bottom:12px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;color:#606266;">REST API 키</label>' +
                        '<div style="position:relative;width:100%;max-width:420px;">' +
                            '<input id="fak-api-key" type="password" placeholder="카카오 앱 REST API 키"' +
                                ' style="width:100%;padding:8px 36px 8px 12px;border:1px solid #dcdfe6;' +
                                'border-radius:4px;font-size:14px;color:#606266;box-sizing:border-box;">' +
                            '<button type="button" id="fak-api-key-eye"' +
                                ' style="position:absolute;right:8px;top:50%;transform:translateY(-50%);' +
                                'background:none;border:none;cursor:pointer;padding:0;line-height:1;color:#909399;"' +
                                ' aria-label="REST API 키 표시/숨김">' +
                                makeEyeIcon(false) +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div style="margin-bottom:12px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;color:#606266;">Client Secret</label>' +
                        '<div style="position:relative;width:100%;max-width:420px;">' +
                            '<input id="fak-client-secret" type="password" placeholder="카카오 앱 Client Secret (권장)"' +
                                ' style="width:100%;padding:8px 36px 8px 12px;border:1px solid #dcdfe6;' +
                                'border-radius:4px;font-size:14px;color:#606266;box-sizing:border-box;">' +
                            '<button type="button" id="fak-client-secret-eye"' +
                                ' style="position:absolute;right:8px;top:50%;transform:translateY(-50%);' +
                                'background:none;border:none;cursor:pointer;padding:0;line-height:1;color:#909399;"' +
                                ' aria-label="Client Secret 표시/숨김">' +
                                makeEyeIcon(false) +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                '<div id="fak-section-wpconfig" style="display:none;">' +
                    '<p style="margin:0 0 8px;font-size:13px;color:#606266;">' +
                        '다음 코드를 wp-config.php 파일에 추가해 주세요 (<strong>***</strong>을 앱 값으로 대체해 주세요)' +
                    '</p>' +
                    '<pre style="background:#f8f8f8;border:1px solid #e4e7ed;border-radius:4px;' +
                         'padding:10px 14px;font-size:13px;color:#303133;overflow-x:auto;margin:0 0 12px;">' +
                        "define('FAK_REST_API_KEY', '******');\n" +
                        "define('FAK_CLIENT_SECRET', '******');" +
                    '</pre>' +
                '</div>' +

                '<div style="margin-bottom:14px;color:#909399;font-size:13px;">' +
                    '카카오 앱 리다이렉트 URI를 설정해 주세요:&nbsp;' +
                    '<code id="fak-redirect-uri" style="background:#fff;border:1px solid #e4e7ed;padding:2px 6px;border-radius:3px;color:#303133;"></code>' +
                '</div>' +

                '<div>' +
                    '<button id="fak-save-btn" type="button"' +
                        ' style="background:#409eff;color:#fff;border:none;padding:8px 20px;' +
                        'border-radius:4px;cursor:pointer;font-size:14px;transition:background .2s;">' +
                        '카카오 설정 저장' +
                    '</button>' +
                    '<span id="fak-msg" style="margin-left:12px;font-size:13px;display:none;"></span>' +
                '</div>' +

            '</div>' +

            '<hr style="border:none;border-top:1px solid #ebeef5;margin:16px 0 14px;">' +

            '<div style="display:flex;align-items:center;gap:10px;">' +
                '<div id="fak-hide-email-toggle" style="width:40px;height:20px;border-radius:10px;' +
                     'background:#dcdfe6;position:relative;cursor:pointer;transition:background .3s;flex-shrink:0;">' +
                    '<div id="fak-hide-email-dot" style="position:absolute;top:2px;left:2px;width:16px;height:16px;' +
                         'background:#fff;border-radius:50%;transition:left .3s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></div>' +
                '</div>' +
                '<span style="font-size:13px;color:#606266;">이메일 로그인 숨기기 (소셜 로그인 버튼만 표시)</span>' +
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

    function setHideEmailToggle(on) {
        var toggle = document.getElementById('fak-hide-email-toggle');
        var dot    = document.getElementById('fak-hide-email-dot');
        if (!toggle) return;
        toggle.style.background = on ? '#409eff' : '#dcdfe6';
        dot.style.left           = on ? '22px' : '2px';
    }

    function setKeyMethod(method) {
        var dbBtn  = document.getElementById('fak-method-db');
        var wcBtn  = document.getElementById('fak-method-wpconfig');
        var dbSec  = document.getElementById('fak-section-db');
        var wcSec  = document.getElementById('fak-section-wpconfig');
        if (!dbBtn) return;
        if (method === 'wp_config') {
            dbBtn.style.cssText  = TAB_BASE + TAB_OFF;
            wcBtn.style.cssText  = TAB_BASE + TAB_ON;
            dbSec.style.display  = 'none';
            wcSec.style.display  = 'block';
        } else {
            dbBtn.style.cssText  = TAB_BASE + TAB_ON;
            wcBtn.style.cssText  = TAB_BASE + TAB_OFF;
            dbSec.style.display  = 'block';
            wcSec.style.display  = 'none';
        }
    }

    function populateCard() {
        if (!loaded) return;
        var apiKey = document.getElementById('fak-api-key');
        if (!apiKey) return;

        var method = settings.key_method || 'db';
        setKeyMethod(method);
        apiKey.value = settings.rest_api_key  || '';
        document.getElementById('fak-client-secret').value = settings.client_secret || '';

        var isOn = method === 'wp_config' || !!settings.rest_api_key;
        setToggle(isOn);
        hideEmailOn = settings.hide_email_login === 'yes';
        setHideEmailToggle(hideEmailOn);
    }

    function bindEvents() {
        var toggle = document.getElementById('fak-toggle');
        var btn    = document.getElementById('fak-save-btn');
        if (!toggle || toggle.dataset.bound) return;
        toggle.dataset.bound = '1';

        bindEyeToggle('fak-api-key', 'fak-api-key-eye');
        bindEyeToggle('fak-client-secret', 'fak-client-secret-eye');

        toggle.addEventListener('click', function () {
            var isOn = document.getElementById('fak-fields').style.display !== 'none';
            setToggle(!isOn);
        });

        document.getElementById('fak-method-db').addEventListener('click', function () {
            settings.key_method = 'db';
            setKeyMethod('db');
        });

        document.getElementById('fak-method-wpconfig').addEventListener('click', function () {
            settings.key_method = 'wp_config';
            setKeyMethod('wp_config');
        });

        document.getElementById('fak-hide-email-toggle').addEventListener('click', function () {
            hideEmailOn = !hideEmailOn;
            setHideEmailToggle(hideEmailOn);
        });

        btn.addEventListener('click', function () {
            var apiKey    = document.getElementById('fak-api-key').value.trim();
            var secret    = document.getElementById('fak-client-secret').value.trim();
            var method    = settings.key_method || 'db';
            var hideEmail = hideEmailOn ? 'yes' : 'no';
            var msg       = document.getElementById('fak-msg');
            btn.disabled  = true;

            fetch(REST_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify({
                    rest_api_key:    method === 'db' ? apiKey : '',
                    client_secret:   method === 'db' ? secret : '',
                    key_method:      method,
                    hide_email_login: hideEmail,
                }),
            })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                settings.key_method = method;
                if (method === 'db') {
                    settings.rest_api_key  = apiKey;
                    settings.client_secret = secret;
                }
                settings.hide_email_login = hideEmail;
                setHideEmailToggle(hideEmail === 'yes');
                msg.textContent   = data.message || '저장됨';
                msg.style.color   = '#67c23a';
                msg.style.display = 'inline';
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
        if (document.getElementById('fak-kakao-card')) return;

        var formEl = null;
        var insertAfter = null;

        // 1차: Github/Google 카드 있으면 같은 form 안, 마지막 카드 뒤에 삽입
        document.querySelectorAll('.fls_login_settings').forEach(function (c) {
            var text = c.textContent || '';
            if (text.includes('Github') || text.includes('Google')) {
                formEl = c.parentNode;
                var siblings = formEl.querySelectorAll(':scope > .fls_login_settings');
                insertAfter = siblings[siblings.length - 1];
            }
        });

        // 2차 폴백: 소셜 토글 OFF라 카드 없음 → box_header로 form 찾기
        if (!formEl) {
            document.querySelectorAll('.box_header').forEach(function (h) {
                var text = h.textContent || '';
                if (text.includes('Social Login') || text.includes('소셜 로그인')) {
                    var boxBody = h.parentNode.querySelector('.box_body');
                    if (boxBody) {
                        formEl = boxBody.querySelector('form.el-form') || boxBody;
                        insertAfter = formEl.lastElementChild;
                    }
                }
            });
        }

        if (!formEl) return;

        var card = buildCard();
        if (insertAfter && insertAfter.nextSibling) {
            formEl.insertBefore(card, insertAfter.nextSibling);
        } else {
            formEl.appendChild(card);
        }

        var redirectEl = card.querySelector('#fak-redirect-uri');
        if (redirectEl) redirectEl.textContent = REDIRECT_URI;

        populateCard();
        bindEvents();
    }

    var observer = new MutationObserver(tryInject);
    observer.observe(document.body, { childList: true, subtree: true });
})();
