<!DOCTYPE html>
<html>
<head>
    <title>{{ $alertSubject }}</title>
</head>
<body>
<h2>Security Alert</h2>
<p><strong>{{ $alertSubject }}</strong></p>
<p>The automated security system triggered the following event:</p>
<table cellpadding="6" cellspacing="0" border="1">
    @foreach($context as $k => $v)
        <tr>
            <td><strong>{{ $k }}</strong></td>
            <td>{{ is_scalar($v) ? $v : json_encode($v) }}</td>
        </tr>
    @endforeach
</table>
<p>Triggered at {{ now()->toDateTimeString() }}.</p>
</body>
</html>
