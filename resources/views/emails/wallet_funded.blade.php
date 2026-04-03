<!DOCTYPE html>
<html>

<head>
    <title>Wallet Funded Successfully</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background-color: #f8f9fa;
            padding: 10px;
            text-align: center;
            border-bottom: 3px solid #051242;
        }

        .header h2 {
            color: #051242;
            margin: 0;
            padding: 10px 0;
        }

        .content {
            padding: 20px;
        }

        .amount-box {
            background-color: #e9ecef;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            border-radius: 5px;
        }

        .amount {
            font-size: 28px;
            font-weight: bold;
            color: #051242;
        }

        .amount-label {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
            width: 40%;
        }

        .balance-row td {
            font-weight: bold;
            color: #051242;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Wallet Funded Successfully</h2>
        </div>
        <div class="content">
            <p>Hello {{ $firstName }},</p>
            <p>Your {{ config('app.name') }} wallet has been credited. Here are the details:</p>

            <div class="amount-box">
                <div class="amount">&#8358;{{ number_format($amountCredited, 2) }}</div>
                <div class="amount-label">Amount Credited to Your Wallet</div>
            </div>

            <table>
                <tr>
                    <th>Amount Received</th>
                    <td>&#8358;{{ number_format($amountReceived, 2) }}</td>
                </tr>
                <tr>
                    <th>Amount Credited</th>
                    <td>&#8358;{{ number_format($amountCredited, 2) }}</td>
                </tr>
                @if($amountReceived !== $amountCredited)
                <tr>
                    <th>Service Charge</th>
                    <td>&#8358;{{ number_format($amountReceived - $amountCredited, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <th>Sent By</th>
                    <td>{{ $payerName }}@if($payerBank) ({{ $payerBank }})@endif</td>
                </tr>
                <tr>
                    <th>Transaction Reference</th>
                    <td>{{ $orderNo }}</td>
                </tr>
                <tr>
                    <th>Date</th>
                    <td>{{ now()->format('d M Y, h:i A') }}</td>
                </tr>
                <tr class="balance-row">
                    <th>New Wallet Balance</th>
                    <td>&#8358;{{ number_format($newBalance, 2) }}</td>
                </tr>
            </table>

            <p style="margin-top: 20px;">If you did not initiate this transaction or have any concerns, please contact our support team immediately.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
