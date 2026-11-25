/**
 * 展示ブースアンケート
 * JavaScript
 */

(function () {
    'use strict';

    // ============================================================
    // 名札OCR（撮影 → クライアント圧縮 → 非同期アップロード → 自動入力）
    // ============================================================
    var JPEG_QUALITY     = 0.8;
    var MAX_LONG_SIDE     = 1568; // 長辺の上限（これ以上はAPI側で縮小されるだけ）
    var MAX_MEGAPIXELS    = 1150000; // 約1.15メガピクセル
    var MIN_LONG_SIDE     = 1000; // 名札の小さい文字が潰れないための下限

    function computeTargetSize(w, h) {
        var longSide = Math.max(w, h);
        var scale = 1;

        if (longSide > MAX_LONG_SIDE) {
            scale = MAX_LONG_SIDE / longSide;
        }

        var mp = (w * scale) * (h * scale);
        if (mp > MAX_MEGAPIXELS) {
            scale = scale * Math.sqrt(MAX_MEGAPIXELS / mp);
        }

        // 長辺がMIN_LONG_SIDEを下回るほどは縮小しない
        if (longSide * scale < MIN_LONG_SIDE) {
            scale = Math.min(1, MIN_LONG_SIDE / longSide);
        }

        // 拡大はしない
        scale = Math.min(scale, 1);

        return {
            width:  Math.max(1, Math.round(w * scale)),
            height: Math.max(1, Math.round(h * scale))
        };
    }

    // EXIF Orientationタグの取得（モバイル撮影の向き対策）
    function getOrientation(file) {
        return new Promise(function (resolve) {
            var reader = new FileReader();
            reader.onload = function (e) {
                try {
                    var view = new DataView(e.target.result);
                    if (view.getUint16(0, false) !== 0xFFD8) { resolve(1); return; }
                    var length = view.byteLength;
                    var offset = 2;
                    while (offset < length) {
                        var marker = view.getUint16(offset, false);
                        offset += 2;
                        if (marker === 0xFFE1) {
                            if (view.getUint32(offset + 2, false) !== 0x45786966) { resolve(1); return; }
                            var little = view.getUint16(offset + 8, false) === 0x4949;
                            var tiffOffset = offset + 8;
                            var firstIFDOffset = view.getUint32(tiffOffset + 4, little);
                            var dirOffset = tiffOffset + firstIFDOffset;
                            var tags = view.getUint16(dirOffset, little);
                            for (var i = 0; i < tags; i++) {
                                var entryOffset = dirOffset + 2 + i * 12;
                                if (view.getUint16(entryOffset, little) === 0x0112) {
                                    resolve(view.getUint16(entryOffset + 8, little));
                                    return;
                                }
                            }
                            resolve(1);
                            return;
                        } else if ((marker & 0xFF00) !== 0xFF00) {
                            break;
                        } else {
                            offset += view.getUint16(offset, false);
                        }
                    }
                } catch (err) { /* 解析失敗時は無回転として扱う */ }
                resolve(1);
            };
            reader.onerror = function () { resolve(1); };
            reader.readAsArrayBuffer(file.slice(0, 128 * 1024));
        });
    }

    function applyOrientation(ctx, orientation, width, height) {
        switch (orientation) {
            case 2: ctx.transform(-1, 0, 0, 1, width, 0); break;
            case 3: ctx.transform(-1, 0, 0, -1, width, height); break;
            case 4: ctx.transform(1, 0, 0, -1, 0, height); break;
            case 5: ctx.transform(0, 1, 1, 0, 0, 0); break;
            case 6: ctx.transform(0, 1, -1, 0, height, 0); break;
            case 7: ctx.transform(0, -1, -1, 0, height, width); break;
            case 8: ctx.transform(0, -1, 1, 0, 0, width); break;
            default: break;
        }
    }

    // 画像ソースを取得する。
    //
    // 実機デバッグの結果、iOS/iPadOS Safariでは createImageBitmap(file, {imageOrientation:'none'})
    // を指定しても実際には無視され、ブラウザが自動で正立補正（EXIF回転を適用）した状態の
    // ピクセルが返っていることが判明した。そこへ getOrientation() で読んだEXIF値を元に
    // applyOrientation() で「追加の」回転をかけていたため、二重回転が発生していた。
    //
    // → 方針変更：createImageBitmapは imageOrientation を指定せず（＝ブラウザの既定挙動＝
    //   自動で正立補正される想定）に任せ、その経路で得た画像には手動回転を一切適用しない
    //   （alreadyOriented: true）。手動回転は、EXIF自動補正が期待できない
    //   Image()フォールバック経路（alreadyOriented: false）でのみ行う。
    // これにより「最終的にCanvasへ描くピクセルが正立しているか」を基準に、
    // 補正が必ず1回だけになるようにする。
    function loadRawImageSource(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file).then(function (bitmap) {
                return {
                    draw:    bitmap,
                    width:   bitmap.width,
                    height:  bitmap.height,
                    cleanup: function () { if (bitmap.close) bitmap.close(); },
                    alreadyOriented: true // ブラウザが自動で正立補正済み → 手動回転は行わない
                };
            }).catch(function () {
                return loadImageSourceViaImg(file);
            });
        }
        return loadImageSourceViaImg(file);
    }

    // createImageBitmap非対応の古いブラウザ向けフォールバック。
    // <img>要素のEXIF自動補正の有無は環境依存で判定が難しいため、
    // 従来通り「補正されていない（raw）」前提でapplyOrientationによる手動補正を適用する。
    function loadImageSourceViaImg(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                resolve({
                    draw:    img,
                    width:   img.naturalWidth,
                    height:  img.naturalHeight,
                    cleanup: function () { URL.revokeObjectURL(url); },
                    alreadyOriented: false // rawピクセル前提で手動補正する
                });
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('画像の読み込みに失敗しました'));
            };
            img.src = url;
        });
    }

    // 撮影画像を EXIF回転補正 → リサイズ → JPEG再エンコード（メタデータも除去される）
    function compressImage(file) {
        return Promise.all([getOrientation(file), loadRawImageSource(file)]).then(function (results) {
            var rawOrientation = results[0];
            var loaded         = results[1];

            // ブラウザ側で既に正立補正済みのソース（createImageBitmap経路）には
            // 手動回転を適用しない＝実効orientationは「補正なし(1)」として扱う。
            // これが二重回転を防ぐための核心部分。
            var orientation = loaded.alreadyOriented ? 1 : rawOrientation;

            try {
                var srcW = loaded.width;
                var srcH = loaded.height;
                var swapped = orientation >= 5 && orientation <= 8;
                var uprightW = swapped ? srcH : srcW;
                var uprightH = swapped ? srcW : srcH;

                // Step1: 向き補正のみ行い、正立画像を作る（alreadyOrientedの場合は無回転コピーになる）
                var uprightCanvas = document.createElement('canvas');
                uprightCanvas.width  = uprightW;
                uprightCanvas.height = uprightH;
                var uctx = uprightCanvas.getContext('2d');
                applyOrientation(uctx, orientation, srcW, srcH);
                uctx.drawImage(loaded.draw, 0, 0);
                loaded.cleanup();

                // Step2: 目標サイズへリサイズ
                var target = computeTargetSize(uprightW, uprightH);
                var outCanvas = document.createElement('canvas');
                outCanvas.width  = target.width;
                outCanvas.height = target.height;
                var octx = outCanvas.getContext('2d');
                octx.drawImage(uprightCanvas, 0, 0, uprightW, uprightH, 0, 0, target.width, target.height);

                return new Promise(function (resolve, reject) {
                    outCanvas.toBlob(function (blob) {
                        if (!blob) { reject(new Error('圧縮に失敗しました')); return; }
                        var fr = new FileReader();
                        fr.onload = function () { resolve({ dataUrl: fr.result, blob: blob }); };
                        fr.onerror = function () { reject(new Error('画像の読込に失敗しました')); };
                        fr.readAsDataURL(blob);
                    }, 'image/jpeg', JPEG_QUALITY);
                });
            } catch (err) {
                loaded.cleanup();
                return Promise.reject(err);
            }
        });
    }

    // OCRで読み取った都道府県名をプルダウンの選択肢に照合して自動選択
    function matchPrefecture(selectEl, text) {
        if (!text) return false;
        var options = selectEl.querySelectorAll('option');
        for (var i = 0; i < options.length; i++) {
            var opt = options[i];
            if (!opt.value) continue;
            if (opt.value === text || text.indexOf(opt.value) !== -1) {
                selectEl.value = opt.value;
                return true;
            }
        }
        // 「都/道/府/県」抜きの表記にも対応（例: 「東京」→「東京都」）
        for (var j = 0; j < options.length; j++) {
            var opt2 = options[j];
            if (!opt2.value) continue;
            var short = opt2.value.replace(/(都|道|府|県)$/, '');
            if (short && (text.indexOf(short) !== -1 || short.indexOf(text) !== -1)) {
                selectEl.value = opt2.value;
                return true;
            }
        }
        return false;
    }

    function initOcr() {
        var input       = document.getElementById('ocr-input');
        var btnLabel    = document.getElementById('ocr-btn-label');
        var guide       = document.getElementById('ocr-guide');
        var previewWrap = document.getElementById('ocr-preview-wrap');
        var preview     = document.getElementById('ocr-preview');
        var retakeBtn   = document.getElementById('ocr-retake');
        var statusEl    = document.getElementById('ocr-status');

        if (!input || !statusEl) return;

        function setStatus(type, msg) {
            statusEl.className = 'ocr-status ' + type;
            if (type === 'loading') {
                statusEl.innerHTML = '<span class="ocr-spinner"></span><span>' + msg + '</span>';
            } else {
                statusEl.textContent = msg;
            }
        }

        function clearStatus() {
            statusEl.className = 'ocr-status';
            statusEl.textContent = '';
        }

        function resetToCapture() {
            if (guide) guide.style.display = '';
            if (btnLabel) btnLabel.style.display = '';
            if (previewWrap) previewWrap.style.display = 'none';
            clearStatus();
            input.value = '';
        }

        function applyOcrResult(data) {
            var nameField     = document.getElementById('name');
            var facilityField = document.getElementById('facility');
            var prefSelect    = document.getElementById('prefecture');
            var filled = [];

            if (data.name && nameField) {
                nameField.value = data.name;
                filled.push('ご芳名');
            }
            if (data.facility && facilityField) {
                facilityField.value = data.facility;
                filled.push('ご所属');
            }
            if (data.prefecture && prefSelect) {
                if (matchPrefecture(prefSelect, data.prefecture)) filled.push('都道府県');
            }

            if (filled.length > 0) {
                setStatus('success', '✓ ' + filled.join('・') + ' を入力しました（内容をご確認のうえ、必要に応じて修正してください）');
            } else {
                setStatus('error', '情報を読み取れませんでした。手動で入力してください。');
            }
        }

        input.addEventListener('change', function () {
            var file = input.files[0];
            if (!file) return;

            if (guide) guide.style.display = 'none';
            if (btnLabel) btnLabel.style.display = 'none';
            if (previewWrap) previewWrap.style.display = 'block';
            setStatus('loading', '画像を処理しています…');

            // 撮影 → 圧縮 → アップロード → OCR はここから非同期に実行される。
            // このPromiseチェーンは待たずに他の設問への入力を続けられる。
            compressImage(file).then(function (result) {
                if (preview) preview.src = result.dataUrl;
                setStatus('loading', '解析中…');
                return fetch('api/ocr.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image: result.dataUrl })
                });
            }).then(function (res) {
                return res.json();
            }).then(function (data) {
                if (data.error) {
                    setStatus('error', '読み取り失敗：' + data.error + '（手動で入力・修正できます）');
                    return;
                }
                applyOcrResult(data);
            }).catch(function () {
                setStatus('error', '通信エラーが発生しました。手動で入力してください。');
            });

            // 同じファイルを再選択できるようリセット
            input.value = '';
        });

        if (retakeBtn) {
            retakeBtn.addEventListener('click', function () {
                resetToCapture();
                input.click();
            });
        }
    }

    // ============================================================
    // 「その他」テキスト欄の表示切り替え
    // ============================================================
    function setupOtherToggles() {
        var toggles = [
            { checkId: 'q1_other_check', containerId: 'q1_other_container', type: 'radio' },
            { checkId: 'q2_other_check', containerId: 'q2_other_container', type: 'radio' },
            { checkId: 'q3_other_check', containerId: 'q3_other_container', type: 'checkbox' },
        ];

        toggles.forEach(function (t) {
            if (t.type === 'radio') {
                var check = document.getElementById(t.checkId);
                if (!check) return;
                var groupName = check.name;
                var radios = document.querySelectorAll('input[name="' + groupName + '"]');
                var container = document.getElementById(t.containerId);
                if (!container) return;

                radios.forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        if (this.value === 'その他') {
                            container.classList.add('visible');
                        } else {
                            container.classList.remove('visible');
                            var inp = container.querySelector('input, textarea');
                            if (inp) inp.value = '';
                        }
                    });
                });
            } else {
                var checkEl     = document.getElementById(t.checkId);
                var containerEl = document.getElementById(t.containerId);
                if (!checkEl || !containerEl) return;

                checkEl.addEventListener('change', function () {
                    if (this.checked) {
                        containerEl.classList.add('visible');
                    } else {
                        containerEl.classList.remove('visible');
                        var inputEl = containerEl.querySelector('input, textarea');
                        if (inputEl) inputEl.value = '';
                    }
                });
            }
        });
    }

    // ============================================================
    // フォームバリデーション & 送信
    // ============================================================
    function setupFormSubmit() {
        var form = document.getElementById('surveyForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var existing = form.querySelector('.error-msg');
            if (existing) existing.remove();

            var errors = [];

            // 画面ごとに存在する項目だけを検証する（index.php＝基本情報 / survey.php＝Q1〜Q4）
            var hasBasic     = !!document.getElementById('name');
            var hasQuestions = !!document.querySelector('input[name="q1"]');

            if (hasBasic) {
                var textRequired = [
                    { id: 'name',     label: 'ご芳名' },
                    { id: 'facility', label: 'ご所属' },
                ];
                textRequired.forEach(function (f) {
                    var el = document.getElementById(f.id);
                    if (!el || !el.value.trim()) {
                        errors.push(f.label + ' を入力してください');
                    }
                });

                var pref = document.getElementById('prefecture');
                if (!pref || !pref.value) {
                    errors.push('都道府県 を選択してください');
                }
            }

            var q1 = document.querySelector('input[name="q1"]:checked');
            if (hasQuestions && !q1) {
                errors.push('Q1 を選択してください');
            }
            if (q1 && q1.value === 'その他') {
                var q1other = document.querySelector('input[name="q1_other"]');
                if (!q1other || !q1other.value.trim()) {
                    errors.push('Q1「その他」の内容を入力してください');
                }
            }

            var q2 = document.querySelector('input[name="q2"]:checked');
            if (hasQuestions && !q2) {
                errors.push('Q2 を選択してください');
            }
            if (q2 && q2.value === 'その他') {
                var q2other = document.querySelector('input[name="q2_other"]');
                if (!q2other || !q2other.value.trim()) {
                    errors.push('Q2「その他」の内容を入力してください');
                }
            }

            var q3OtherCheck = document.getElementById('q3_other_check');
            if (q3OtherCheck && q3OtherCheck.checked) {
                var q3other = document.querySelector('input[name="q3_other"]');
                if (!q3other || !q3other.value.trim()) {
                    errors.push('Q3「その他」の内容を入力してください');
                }
            }

            if (errors.length > 0) {
                var errDiv = document.createElement('div');
                errDiv.className = 'error-msg';
                errDiv.textContent = errors[0];
                var submitArea = form.querySelector('.submit-area');
                submitArea.insertBefore(errDiv, submitArea.firstChild);
                errDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            var btn = form.querySelector('.btn-submit');
            if (btn) {
                btn.disabled = true;
                btn.textContent = btn.getAttribute('data-loading-text') || '送信中...';
            }

            form.submit();
        });
    }

    // ============================================================
    // タッチフィードバック
    // ============================================================
    function setupHaptic() {
        document.querySelectorAll('.radio-item input, .checkbox-item input').forEach(function (el) {
            el.addEventListener('change', function () {
                if (navigator.vibrate) navigator.vibrate(8);
            });
        });
    }

    // ============================================================
    // 初期化
    // ============================================================
    function init() {
        initOcr();
        setupOtherToggles();
        setupFormSubmit();
        setupHaptic();
    }

    // ブラウザの「戻る」でキャッシュ（bfcache）から復元された場合は、送信中のまま固まったボタンや
    // 使用済みのワンタイムトークンが残るため、サーバーから取り直す
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            location.reload();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
