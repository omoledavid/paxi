<!DOCTYPE html>
<html>
<head>
    <title>Account Suspended - Security Alert</title>
</head>
<body>
<h1>Account Suspended</h1>
<p>Hello {{ $user->sFname ?? 'there' }},</p>
<p>Your account has been suspended due to suspicious activity on your profile. Our automated security system detected an unusual pattern of transaction activity.</p>
@if(!empty($reason))
<p><strong>Reason:</strong> {{ $reason }}</p>
@endif
<p>If you believe this was a mistake, please contact our support team to review your account.</p>
<p>Thank you for helping us keep the platform safe.</p>
<p>— Paxi Security Team</p>
</body>
</html>
