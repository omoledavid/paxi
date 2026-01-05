<!DOCTYPE html>
<html>

<head>
    <title>Electricity Token Purchase Successful</title>
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
            border-bottom: 3px solid #007bff;
        }

        .content {
            padding: 20px;
        }

        .token-box {
            background-color: #e9ecef;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            border-radius: 5px;
        }

        .token {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            letter-spacing: 2px;
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
            <h2>Your Electricity Token</h2>
        </div>
        <div class="content">
            <p>Hello,</p>
            <p>Thank you for your electricity purchase. Below is your token:</p>

            <div class="token-box">
                <div class="token">{{ $token }}</div>
            </div>

            <table>
                <tr>
                    <th>Transaction Ref</th>
                    <td>{{ $transactionRef }}</td>
                </tr>
                <tr>
                    <th>Meter Number</th>
                    <td>{{ $meterNo }}</td>
                </tr>
                <tr>
                    <th>Amount</th>
                    <td>{{ number_format($amount, 2) }}</td>
                </tr>
            </table>

            <p>Please enter this token into your meter to recharge.</p>
            <p>If you have any issues, please contact our support team.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>