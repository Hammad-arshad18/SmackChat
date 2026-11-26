@php($contact = config('app.support_email') ?: config('mail.from.address'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy · {{ config('app.name', 'SmackChat') }}</title>
    <meta name="description" content="How SmackChat handles your data: what we collect, why, who we share it with, and how to delete it.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/privacy">
    <style>
        :root { color-scheme: light dark; --bg: #FAF9F6; --text: #1B1A17; --muted: #6B6860; --line: #E4E1DA; --accent: #2F6B4F; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111214; --text: #ECEAE4; --muted: #A09D95; --line: #2A2B2F; --accent: #7BC6A0; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 48px 20px 80px; }
        h1 { font-size: 34px; line-height: 1.2; margin: 0 0 6px; letter-spacing: -0.02em; }
        h2 { font-size: 20px; margin: 40px 0 8px; padding-top: 20px; border-top: 1px solid var(--line); }
        p, li { color: var(--text); }
        ul { padding-left: 22px; }
        li { margin: 6px 0; }
        .muted { color: var(--muted); }
        a { color: var(--accent); }
        table { width: 100%; border-collapse: collapse; font-size: 15px; margin: 12px 0; }
        th, td { text-align: left; vertical-align: top; padding: 10px 8px; border-bottom: 1px solid var(--line); }
        th { font-weight: 600; }
        @media (max-width: 560px) { table, tbody, tr, td, th { display: block; } thead { display: none; } td { border: 0; padding: 2px 0; } tr { padding: 10px 0; border-bottom: 1px solid var(--line); } td:first-child { font-weight: 600; } }
    </style>
</head>
<body>
<main>
    <p class="muted"><a href="{{ url('/') }}">{{ config('app.name', 'SmackChat') }}</a></p>
    <h1>Privacy Policy</h1>
    <p class="muted">Last updated 26 November 2026</p>

    <p>SmackChat is a workplace messaging, calling and attendance service, available on the web and as apps for Android and iOS. This policy explains what data the service handles, why, who it is shared with, and the choices you have. It applies to the website at {{ preg_replace('#^https?://#', '', rtrim(config('app.url'), '/')) }} and to the SmackChat mobile apps.</p>

    <p>SmackChat is used by organisations (“workspaces”). People join a workspace only when an owner or admin invites them. Your workspace’s owners and admins decide how the workspace is used. For data inside a workspace, such as attendance records, your organisation is generally the party responsible for that data, and we process it for them.</p>

    <h2>Data we collect and why</h2>
    <table>
        <thead><tr><th>Data</th><th>Why we use it</th></tr></thead>
        <tbody>
        <tr><td>Name, email address, password (stored only as a secure hash), profile photo, status</td><td>To create and secure your account, show you to your teammates, and send account emails such as invitations and password resets.</td></tr>
        <tr><td>Messages you write</td><td>To deliver them. Messages you write are end-to-end encrypted on your device. The server stores only ciphertext and cannot read them. Some automatic posts made by the service itself (for example welcome notes and attendance summaries) are not encrypted so they can be shown in timesheets.</td></tr>
        <tr><td>Files and photos you attach</td><td>To store and deliver them to the members of the conversation. Attachments are not end-to-end encrypted: they are stored on our server so they can be served.</td></tr>
        <tr><td>Encryption keys</td><td>Your device creates its own keys. A backup of your keys is stored on the server, locked with a key derived from your password on your device, so you can read your messages on a new device. We cannot open it.</td></tr>
        <tr><td>Attendance records: clock-in and clock-out times, breaks, and the precise location and IP address at the moment you clock in or out</td><td>To record working time and let your workspace check that people clock in from the office, when the workspace turns those checks on. Location is read only when you tap to clock in or out, never in the background.</td></tr>
        <tr><td>Calls: microphone and camera</td><td>To carry voice and video calls you start or answer. Call audio and video travel directly between participants (or through a relay server when a direct connection is not possible) and are not recorded or stored.</td></tr>
        <tr><td>Device and push information: device name, push notification tokens</td><td>To keep you signed in on that device and to deliver message notifications and incoming-call alerts. Notification content never contains the text of encrypted messages.</td></tr>
        <tr><td>Security logs: IP address, browser or device type, sign-in and admin actions</td><td>To protect accounts, stop abuse, and give workspace admins an audit log.</td></tr>
        </tbody>
    </table>
    <p>We do not sell your data, we do not show ads, and we do not use advertising identifiers or third-party analytics or tracking in the apps.</p>

    <h2>Who we share data with</h2>
    <ul>
        <li><strong>Members of your workspace</strong> see your profile and the messages, files and attendance information that the workspace’s settings allow.</li>
        <li><strong>Service providers</strong> who run parts of the service for us, under contract, and only for that purpose: hosting and storage, email delivery, real-time messaging delivery (which carries only encrypted message content and metadata), call relay servers, and push notification services (Google Firebase Cloud Messaging, Apple Push Notification service, Expo, and your browser’s push service).</li>
        <li><strong>Legal reasons</strong>: when the law requires it, or to protect the rights and safety of our users and the service. End-to-end encrypted messages cannot be disclosed in readable form because we do not hold the keys.</li>
    </ul>

    <h2>Security</h2>
    <p>Data is sent over encrypted connections (HTTPS/TLS). Passwords are hashed. Sign-in tokens on phones are kept in the operating system’s secure storage. Messages you write are end-to-end encrypted as described above.</p>

    <h2>How long we keep data</h2>
    <p>We keep your account data for as long as your account exists. Workspace data, such as messages, files and attendance records, is kept for as long as the workspace keeps it or until it is deleted by its owners. Security logs are kept only as long as needed to protect the service. When an account or workspace is deleted, its data is removed from our active systems, and from backups as they are replaced.</p>

    <h2>Your choices and rights</h2>
    <ul>
        <li><strong>Access and export</strong>: you can download a copy of your data from your account settings, in the app or on the web.</li>
        <li><strong>Correction</strong>: you can change your name, photo and status at any time.</li>
        <li><strong>Deletion</strong>: you can delete your account from your account settings, in the app (Settings → Delete account) or on the web. You can also ask us to delete it by emailing us. If you own a workspace, transfer or delete it first.</li>
        <li><strong>Permissions</strong>: you can turn off location, camera, microphone and notification access in your phone’s settings at any time. Features that need them (clocking in with location checks, calls, alerts) will stop working until you turn them back on.</li>
    </ul>
    <p>Depending on where you live, you may have further rights, such as to object to processing or to complain to a data protection authority. Contact us to use them.</p>

    <h2>Children</h2>
    <p>SmackChat is a workplace tool and is not directed at children under 13. We do not knowingly collect data from children under 13. If you believe a child under 13 has an account, contact us and we will delete it.</p>

    <h2>Changes</h2>
    <p>If we change this policy, we will update the date at the top of this page, and tell you in the app when the change is significant.</p>

    <h2>Contact</h2>
    <p>Questions or requests about privacy:
        @if ($contact)
            <a href="mailto:{{ $contact }}">{{ $contact }}</a>
        @else
            use the contact details on our Google Play or App Store listing.
        @endif
    </p>
</main>
</body>
</html>
