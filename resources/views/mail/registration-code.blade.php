<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#f5f3ec;font-family:Arial,sans-serif;color:#26241f;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr><td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border:1px solid #e3ded1;border-radius:18px;padding:32px;">
                <tr><td>
                    <p style="margin:0 0 8px;font-size:12px;letter-spacing:2px;color:#0e7c66;font-weight:bold;">SMACKCHAT</p>
                    <h1 style="margin:0 0 12px;font-size:24px;letter-spacing:-0.5px;">Verify your email</h1>
                    <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#756d5d;">Use this one-time code to finish creating your workspace. It expires in {{ $validMinutes }} minutes.</p>
                    <div style="padding:20px;background:#f0ede4;border-radius:12px;text-align:center;font-size:34px;font-weight:bold;letter-spacing:10px;color:#0a6356;">{{ $code }}</div>
                    <p style="margin:20px 0 0;font-size:12px;line-height:1.6;color:#928875;">If you did not request a SmackChat workspace, you can ignore this email. Do not share this code with anyone.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
