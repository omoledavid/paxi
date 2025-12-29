<!DOCTYPE html>
<html>

<head>
    <title>EPIN Purchase Successful</title>
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
            <h2>Your Purchase Was Successful!</h2>
        </div>
        <div class="content">
            <p>Hello,</p>
            <p>Thank you for your purchase. Here are the details of your EPIN(s):</p>

            <p><strong>Transaction Ref:</strong> {{ $transactionRef }}</p>

            <table>
                <thead>
                    <tr>
                        <th>Network</th>
                        <th>Value</th>
                        <th>PIN</th>
                        <th>Serial No</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($epins as $epin)
                        <tr>
                            <td>{{ $epin['network'] }}</td>
                            <td>{{ number_format($epin['amount'], 2) }}</td>
                            <td style="font-weight: bold; letter-spacing: 1px;">{{ $epin['pin_code'] }}</td>
                            <td>{{ $epin['serial_number'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p>If you have any issues, please contact our support team.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>