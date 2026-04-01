<?php
/**
 * Send a styled verification email matching ANORA's dark UI.
 * Uses PHP built-in mail(). Swap for PHPMailer + SMTP in production.
 */
function sendVerificationEmail(string $to, string $token): bool {
    $link    = 'https://anora.bet/verify?token=' . urlencode($token);
    $subject = 'Verify your ANORA account';
    $headers = implode("\r\n", [
        'From: ANORA <noreply@anora.bet>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ]);

    $body = buildVerificationHtml($link);
    return mail($to, $subject, $body, $headers);
}

function buildVerificationHtml(string $link): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

  <!-- Logo -->
  <tr><td align="center" style="padding:0 0 32px;">
    <div style="font-size:28px;font-weight:800;letter-spacing:2px;color:#a78bfa;">
      &#9878; ANORA
    </div>
    <div style="font-size:12px;color:#6b7280;margin-top:4px;">Provably Fair Lottery Platform</div>
  </td></tr>

  <!-- Main Card -->
  <tr><td style="background:linear-gradient(145deg,#13131a,#1a1a2e);border:1px solid rgba(124,58,237,0.2);border-radius:16px;padding:40px 32px;">

    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#e5e7eb;">Welcome to ANORA</h1>
    <p style="margin:0 0 24px;font-size:14px;color:#9ca3af;line-height:1.6;">
      You're one step away from joining the game. Verify your email to activate your account and start playing.
    </p>

    <!-- CTA Button -->
    <table cellpadding="0" cellspacing="0" style="margin:0 0 32px;"><tr><td>
      <a href="{$link}" target="_blank" style="
        display:inline-block;padding:14px 40px;
        background:linear-gradient(135deg,#7c3aed,#a855f7);
        color:#ffffff;font-size:15px;font-weight:700;
        text-decoration:none;border-radius:10px;
        box-shadow:0 4px 20px rgba(124,58,237,0.4);
      ">Verify My Email</a>
    </td></tr></table>

    <p style="margin:0 0 24px;font-size:12px;color:#6b7280;line-height:1.5;">
      Or copy this link:<br>
      <a href="{$link}" style="color:#a78bfa;word-break:break-all;text-decoration:none;">{$link}</a>
    </p>

    <!-- Divider -->
    <div style="border-top:1px solid rgba(255,255,255,0.06);margin:24px 0;"></div>

    <!-- Feature blocks -->
    <h2 style="margin:0 0 16px;font-size:16px;font-weight:600;color:#e5e7eb;">Why ANORA?</h2>

    <!-- Feature: Provably Fair -->
    <table cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;"><tr>
      <td width="44" valign="top" style="padding-right:12px;">
        <div style="width:40px;height:40px;background:rgba(245,158,11,0.1);border-radius:10px;text-align:center;line-height:40px;font-size:18px;">&#128274;</div>
      </td>
      <td valign="top">
        <div style="font-size:14px;font-weight:600;color:#e5e7eb;margin-bottom:2px;">Provably Fair</div>
        <div style="font-size:13px;color:#9ca3af;line-height:1.5;">Every round uses SHA-256 hashing with server + client seeds. Results are cryptographically verifiable — no manipulation possible.</div>
      </td>
    </tr></table>

    <!-- Feature: Game Rooms -->
    <table cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;"><tr>
      <td width="44" valign="top" style="padding-right:12px;">
        <div style="width:40px;height:40px;background:rgba(16,185,129,0.1);border-radius:10px;text-align:center;line-height:40px;font-size:18px;">&#127922;</div>
      </td>
      <td valign="top">
        <div style="font-size:14px;font-weight:600;color:#e5e7eb;margin-bottom:2px;">Three Game Rooms</div>
        <div style="font-size:13px;color:#9ca3af;line-height:1.5;">Choose your stakes: &#36;1, &#36;10, or &#36;100 rooms. The more you bet, the higher your chance to win the pot.</div>
      </td>
    </tr></table>

    <!-- Feature: Crypto -->
    <table cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;"><tr>
      <td width="44" valign="top" style="padding-right:12px;">
        <div style="width:40px;height:40px;background:rgba(249,115,22,0.1);border-radius:10px;text-align:center;line-height:40px;font-size:18px;">&#8383;</div>
      </td>
      <td valign="top">
        <div style="font-size:14px;font-weight:600;color:#e5e7eb;margin-bottom:2px;">Crypto Payments</div>
        <div style="font-size:13px;color:#9ca3af;line-height:1.5;">Deposit and withdraw with BTC, ETH, USDT, and 50+ cryptocurrencies. Instant deposits, fast withdrawals.</div>
      </td>
    </tr></table>

    <!-- Feature: Referrals -->
    <table cellpadding="0" cellspacing="0" style="margin:0 0 4px;width:100%;"><tr>
      <td width="44" valign="top" style="padding-right:12px;">
        <div style="width:40px;height:40px;background:rgba(168,85,247,0.1);border-radius:10px;text-align:center;line-height:40px;font-size:18px;">&#128101;</div>
      </td>
      <td valign="top">
        <div style="font-size:14px;font-weight:600;color:#e5e7eb;margin-bottom:2px;">Referral Program</div>
        <div style="font-size:13px;color:#9ca3af;line-height:1.5;">Invite friends and earn 1% of the pot every time they win. Share your link, grow your earnings.</div>
      </td>
    </tr></table>

  </td></tr>

  <!-- Footer -->
  <tr><td align="center" style="padding:24px 0 0;">
    <p style="margin:0 0 8px;font-size:12px;color:#4b5563;">
      If you didn't create an account on ANORA, you can safely ignore this email.
    </p>
    <p style="margin:0;font-size:11px;color:#374151;">
      &copy; 2025 ANORA &middot; anora.bet
    </p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
