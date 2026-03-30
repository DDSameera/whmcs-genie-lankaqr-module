<?php
/**
 * WHMCS LANKAQR Payment Gateway Module
 * ══════════════════════════════════════════════════════════════
 * Genie Biz / Dialog LANKAQR — Dynamic QR per invoice
 *
 * Install path: /path/to/whmcs/modules/gateways/lankaqr.php
 *
 * Verified against:
 *   - Genie Biz LANKAQR spec v1.00 Rev.03
 *   - WHMCS Gateway Module Developer Docs
 * ══════════════════════════════════════════════════════════════
 */

if (!defined('WHMCS')) die('Direct access not permitted');

/* ═══════════════════════════════════════════════════════════════
   1. MODULE META + CONFIGURATION
═══════════════════════════════════════════════════════════════ */

function lankaqr_MetaData(): array
{
    return [
        'DisplayName' => 'LANKA QR (Genie Biz)',
        'APIVersion'  => '1.1',
    ];
}

function lankaqr_config(): array
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'LANKA QR (Genie Biz)',
        ],
        'merchant_id' => [
            'FriendlyName' => 'QR Merchant ID',
            'Type'         => 'text',
            'Size'         => 30,
            'Description'  => 'From Genie Biz dashboard → QR Codes (decode your QR to get this)',
        ],
        'terminal_id' => [
            'FriendlyName' => 'QR Terminal ID',
            'Type'         => 'text',
            'Size'         => 10,
            'Description'  => 'From Genie Biz dashboard → QR Codes',
        ],
        'mcc' => [
            'FriendlyName' => 'Merchant Category Code (MCC)',
            'Type'         => 'text',
            'Size'         => 6,
            'Description'  => 'Your business MCC (found inside your Genie QR)',
        ],
        'merchant_name' => [
            'FriendlyName' => 'Merchant Name',
            'Type'         => 'text',
            'Size'         => 25,
            'Description'  => 'Max 25 characters (LANKAQR spec limit)',
        ],
        'merchant_city' => [
            'FriendlyName' => 'Merchant City',
            'Type'         => 'text',
            'Size'         => 15,
            'Description'  => 'Max 15 characters (LANKAQR spec limit)',
        ],
        'qr_expiry_minutes' => [
            'FriendlyName' => 'QR Expiry (minutes)',
            'Type'         => 'text',
            'Size'         => 5,
            'Description'  => 'How long the QR stays valid on checkout page',
        ],
        'poll_interval' => [
            'FriendlyName' => 'Payment Poll Interval (seconds)',
            'Type'         => 'text',
            'Size'         => 5,
            'Description'  => 'How often to check for payment confirmation',
        ],
        'testmode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Show QR without requiring actual payment (for testing layout)',
        ],
    ];
}

/* ═══════════════════════════════════════════════════════════════
   2. LANKAQR PAYLOAD GENERATOR
═══════════════════════════════════════════════════════════════ */

/**
 * Build one TLV field.
 */
function _lqr_tlv(string $id, string $value): string
{
    return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

/**
 * CRC-16/CCITT-FALSE (poly=0x1021, init=0xFFFF)
 * Verified ✓ against spec page 7 sample — CRC = 3D10
 */
function _lqr_crc16(string $data): string
{
    $crc = 0xFFFF;
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $crc ^= (ord($data[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000)
                ? (($crc << 1) ^ 0x1021) & 0xFFFF
                : ($crc << 1) & 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/**
 * Format amount: whole numbers without decimals (2000 not 2000.00)
 */
function _lqr_amount(float $amount): string
{
    return (floor($amount) === $amount)
        ? (string)(int)$amount
        : number_format($amount, 2, '.', '');
}

/**
 * Build Tag 26 — Merchant Account Information
 * Structure: 26<len> 00<len> <1><6995><001><MerchantID><TerminalID>
 * Verified ✓ against spec page 5 example
 */
function _lqr_tag26(string $merchantId, string $terminalId): string
{
    $inner = '1' . '6995' . '001' . $merchantId . $terminalId;
    return _lqr_tlv('26', _lqr_tlv('00', $inner));
}

/**
 * Generate full LANKAQR payload string.
 *
 * @param float  $amount      Invoice amount in LKR
 * @param string $reference   Invoice number / order reference (max 25 chars)
 * @param array  $params      Gateway config params
 */
function lankaqr_generatePayload(float $amount, string $reference, array $params): string
{
    $merchantId   = $params['merchant_id'];
    $terminalId   = $params['terminal_id'];
    $mcc          = $params['mcc'];
    $merchantName = substr($params['merchant_name'], 0, 25);
    $merchantCity = substr($params['merchant_city'], 0, 15);
    $reference    = substr($reference, 0, 25);

    $p  = _lqr_tlv('00', '01');                    // Payload Format Indicator
    $p .= _lqr_tlv('01', '12');                    // Point of Initiation = Dynamic (amount fixed)
    $p .= _lqr_tag26($merchantId, $terminalId);    // Merchant Account Info
    $p .= _lqr_tlv('52', $mcc);                    // MCC
    $p .= _lqr_tlv('53', '144');                   // Currency = LKR
    $p .= _lqr_tlv('54', _lqr_amount($amount));    // Amount
    $p .= _lqr_tlv('58', 'LK');                    // Country Code
    $p .= _lqr_tlv('59', $merchantName);           // Merchant Name
    $p .= _lqr_tlv('60', $merchantCity);           // Merchant City
    $p .= _lqr_tlv('62', _lqr_tlv('05', $reference)); // Additional Data: Reference Label

    $forCrc = $p . '6304';
    return $forCrc . _lqr_crc16($forCrc);
}

/* ═══════════════════════════════════════════════════════════════
   3. WHMCS PAYMENT LINK / CHECKOUT PAGE
═══════════════════════════════════════════════════════════════ */

function lankaqr_link(array $params): string
{
    // ── Invoice details ──────────────────────────────────────
    $invoiceId  = $params['invoiceid'];
    $amount     = (float)$params['amount'];
    $currency   = $params['currency'];
    $reference  = 'INV-' . $invoiceId;

    // ── Config ───────────────────────────────────────────────
    $expiryMins   = max(1, (int)($params['qr_expiry_minutes'] ?? 15));
    $pollInterval = max(3, (int)($params['poll_interval'] ?? 5));
    $testMode     = ($params['testmode'] === 'on');
    $systemUrl    = $params['systemurl'];
    $callbackUrl  = $systemUrl . 'modules/gateways/callback/lankaqr.php';

    // ── Only LKR supported ───────────────────────────────────
    if (strtoupper($currency) !== 'LKR') {
        return '<div style="color:#c0392b;padding:12px;border:1px solid #e74c3c;border-radius:6px;">'
             . '⚠️ LANKAQR supports LKR only. Current currency: ' . htmlspecialchars($currency)
             . '</div>';
    }

    // ── Generate LANKAQR payload ──────────────────────────────
    $payload = lankaqr_generatePayload($amount, $reference, $params);

    // ── Expiry timestamp ─────────────────────────────────────
    $expiresAt = time() + ($expiryMins * 60);

    // ── Unique element IDs (support multiple on same page) ───
    $uid = 'lqr_' . $invoiceId . '_' . substr(md5($payload), 0, 6);

    ob_start();
    ?>
    <div id="<?= $uid ?>_wrap" class="lankaqr-wrap">

        <!-- ── Amount display ── -->
        <!--<div class="lqr-amount-bar">-->
        <!--    <span class="lqr-currency">LKR</span>-->
        <!--    <span class="lqr-amount"><?= number_format($amount, 2) ?></span>-->
        <!--</div>-->

        <!-- ── Instructions ── -->
        <ol class="lqr-steps">
            <li>Open your QR Reader App</li>
            <li>Tap <strong>Scan QR</strong></li>
            <li>Scan the code below and confirm payment</li>
        </ol>

        <!-- ── QR Code ── -->
        <div class="lqr-qr-outer">
            <div id="<?= $uid ?>_qr" class="lqr-qr-inner"></div>
            <div class="lqr-ref">Ref: <?= htmlspecialchars($reference) ?></div>
        </div>

        <!-- ── Timer ── -->
        <div class="lqr-timer-wrap">
            <span class="lqr-timer-label">QR expires in</span>
            <span id="<?= $uid ?>_timer" class="lqr-timer">--:--</span>
        </div>

        <!-- ── Status ── -->
        <div id="<?= $uid ?>_status" class="lqr-status lqr-waiting">
            <span class="lqr-dot"></span>
            <span id="<?= $uid ?>_status_text">Waiting for payment…</span>
        </div>

        <!-- ── Expired overlay ── -->
        <div id="<?= $uid ?>_expired" class="lqr-expired" style="display:none;">
            <p>⏰ QR code expired.</p>
            <button onclick="location.reload()" class="lqr-btn-refresh">Generate New QR</button>
        </div>

    </div>

    <!-- ════ STYLES ════ -->
    <style>
    .lankaqr-wrap {
        font-family: -apple-system, 'Segoe UI', sans-serif;
        max-width: 360px;
        margin: 0 auto;
        padding: 24px 20px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 4px 24px rgba(0,0,0,.07);
        position: relative;
        text-align: center;
    }
    .lqr-amount-bar {
        background: linear-gradient(135deg, #f97316, #e8001c);
        border-radius: 10px;
        padding: 14px 20px;
        margin-bottom: 18px;
        color: #fff;
    }
    .lqr-currency { font-size: 14px; font-weight: 600; opacity: .85; margin-right: 6px; }
    .lqr-amount   { font-size: 28px; font-weight: 800; letter-spacing: -.5px; }

    .lqr-steps {
        text-align: left;
        font-size: 13px;
        color: #64748b;
        margin: 0 0 18px 18px;
        padding: 0;
        line-height: 1.8;
    }
    .lqr-steps strong { color: #1e293b; }

    .lqr-qr-outer {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 14px;
        display: inline-block;
        width: 100%;
    }
    .lqr-qr-inner {
        display: flex;
        justify-content: center;
        margin-bottom: 8px;
    }
    .lqr-qr-inner canvas, .lqr-qr-inner img {
        border-radius: 8px;
        border: 4px solid #fff;
        box-shadow: 0 2px 12px rgba(0,0,0,.1);
    }
    .lqr-ref {
        font-size: 11px;
        color: #94a3b8;
        font-family: monospace;
    }

    .lqr-timer-wrap {
        font-size: 13px;
        color: #64748b;
        margin-bottom: 12px;
    }
    .lqr-timer {
        font-size: 18px;
        font-weight: 700;
        color: #f97316;
        margin-left: 6px;
        font-variant-numeric: tabular-nums;
    }
    .lqr-timer.urgent { color: #e8001c; animation: lqr-pulse 1s infinite; }
    @keyframes lqr-pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

    .lqr-status {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 500;
        padding: 10px 16px;
        border-radius: 8px;
        margin-top: 4px;
    }
    .lqr-waiting  { background: #f1f5f9; color: #64748b; }
    .lqr-success  { background: #dcfce7; color: #16a34a; }
    .lqr-failed   { background: #fee2e2; color: #dc2626; }

    .lqr-dot {
        width: 8px; height: 8px; border-radius: 50%;
        background: currentColor;
        animation: lqr-blink 1.4s infinite;
    }
    .lqr-success .lqr-dot, .lqr-failed .lqr-dot { animation: none; }
    @keyframes lqr-blink { 0%,100%{opacity:1} 50%{opacity:.2} }

    .lqr-expired {
        position: absolute; inset: 0;
        background: rgba(255,255,255,.92);
        border-radius: 16px;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        gap: 14px; font-size: 15px; font-weight: 600; color: #475569;
    }
    .lqr-btn-refresh {
        background: #f97316; color: #fff; border: none;
        padding: 10px 24px; border-radius: 8px;
        font-size: 14px; font-weight: 600; cursor: pointer;
    }
    .lqr-btn-refresh:hover { background: #ea6c0a; }
    </style>

    <!-- ════ SCRIPTS ════ -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    (function() {
        var payload     = <?= json_encode($payload) ?>;
        var expiresAt   = <?= $expiresAt ?>;
        var pollSecs    = <?= $pollInterval ?>;
        var invoiceId   = <?= (int)$invoiceId ?>;
        var callbackUrl = <?= json_encode($callbackUrl) ?>;
        var testMode    = <?= $testMode ? 'true' : 'false' ?>;
        var uid         = <?= json_encode($uid) ?>;

        var wrap        = document.getElementById(uid + '_wrap');
        var timerEl     = document.getElementById(uid + '_timer');
        var statusEl    = document.getElementById(uid + '_status');
        var statusText  = document.getElementById(uid + '_status_text');
        var expiredEl   = document.getElementById(uid + '_expired');
        var expired     = false;
        var confirmed   = false;

        // ── Generate QR ──────────────────────────────────────
        new QRCode(document.getElementById(uid + '_qr'), {
            text: payload,
            width: 200, height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });

        // ── Countdown timer ──────────────────────────────────
        function updateTimer() {
            if (confirmed) return;
            var remaining = expiresAt - Math.floor(Date.now() / 1000);
            if (remaining <= 0) {
                timerEl.textContent = '00:00';
                expired = true;
                expiredEl.style.display = 'flex';
                return;
            }
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
            if (remaining <= 60) timerEl.classList.add('urgent');
            else timerEl.classList.remove('urgent');
        }
        updateTimer();
        var timerInterval = setInterval(function() {
            updateTimer();
            if (expired) clearInterval(timerInterval);
        }, 1000);

        // ── Payment status polling ────────────────────────────
        function setStatus(type, text) {
            statusEl.className = 'lqr-status lqr-' + type;
            statusText.textContent = text;
        }

        function pollPayment() {
            if (expired || confirmed) return;
            if (testMode) {
                // Test mode: simulate confirmed after 10 seconds
                setTimeout(function() {
                    setStatus('success', '✅ Payment confirmed! (test mode)');
                    confirmed = true;
                }, 10000);
                return;
            }

            fetch(callbackUrl + '?action=check&invoice_id=' + invoiceId + '&t=' + Date.now())
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'paid') {
                        confirmed = true;
                        setStatus('success', '✅ Payment confirmed! Redirecting…');
                        clearInterval(pollInterval);
                        clearInterval(timerInterval);
                        // Redirect to invoice after short delay
                        setTimeout(function() {
                            window.location.href = data.redirect || (window.location.origin + '/viewinvoice.php?id=' + invoiceId);
                        }, 2000);
                    } else if (data.status === 'failed') {
                        setStatus('failed', '❌ Payment failed. Please try again.');
                        clearInterval(pollInterval);
                    }
                    // 'pending' → keep polling
                })
                .catch(function() {
                    // Network error — keep polling silently
                });
        }

        var pollInterval = setInterval(function() {
            if (!expired && !confirmed) pollPayment();
        }, pollSecs * 1000);

        // Initial poll after 5 seconds
        setTimeout(pollPayment, 5000);

    })();
    </script>
    <?php
    return ob_get_clean();
}

/* ═══════════════════════════════════════════════════════════════
   4. REFUND (not applicable for QR — stub required by WHMCS)
═══════════════════════════════════════════════════════════════ */

function lankaqr_refund(array $params): array
{
    return [
        'status'  => 'error',
        'rawdata' => 'LANKAQR does not support automated refunds. Please process manually via Genie Biz dashboard.',
    ];
}
