<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Feedback Submitted</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #2c3e50;">New Feedback Submitted</h2>
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #495057;">Feedback Details</h3>
            <p><strong>Subject:</strong> {{ $feedback->subject }}</p>
            <p><strong>Status:</strong> {{ $feedback->status }}</p>
            <p><strong>Submitted:</strong> {{ $feedback->created_at->format('Y-m-d H:i:s') }}</p>
        </div>

        <div style="background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #1976d2;">User Information</h3>
            <p><strong>Name:</strong> {{ $user->sFname }} {{ $user->sLname }}</p>
            <p><strong>Email:</strong> {{ $user->sEmail }}</p>
            <p><strong>Phone:</strong> {{ $user->sPhone ?? 'N/A' }}</p>
            <p><strong>Username:</strong> {{ $user->username }}</p>
        </div>

        <div style="background: #fff3e0; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #f57c00;">Message</h3>
            <p style="white-space: pre-wrap;">{{ $feedback->message }}</p>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ config('app.admin_url') }}/ceegatpaxiadmin/dashboard/feedback-details?id{{ $feedback->id }}" 
               style="background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
                View Feedback in Admin Panel
            </a>
        </div>

        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        <p style="text-align: center; color: #6c75d7; font-size: 12px;">
            This is an automated notification from {{ config('app.name') }}.
        </p>
    </div>
</body>
</html>
