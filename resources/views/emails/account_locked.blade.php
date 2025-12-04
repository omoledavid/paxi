<!DOCTYPE html>
<html>
<head>
    <title>Account Locked - Security Alert</title>
</head>
<body>
<h1>Security Alert</h1>
<p>Hello {{ $user->sFname }},</p>
<p>Your account has been temporarily locked due to multiple failed login attempts.</p>
<p><strong>What happened?</strong></p>
<p>We detected 3 unsuccessful login attempts on your account. For your security, we've temporarily locked your account.</p>
<p><strong>How to unlock your account:</strong></p>
<p>You can unlock your account by:</p>
<ul>
    <li>Resetting your password using the password reset feature</li>
    <li>Waiting 30 minutes for automatic unlock</li>
</ul>
<p>If you didn't attempt to log in, please reset your password immediately to secure your account.</p>
<p>If you have any concerns, please contact our support team.</p>
<p>Stay safe!</p>
</body>
</html>

