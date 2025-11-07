<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - License Validation Failed</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 500px;
            text-align: center;
            margin: 20px;
        }
        .icon {
            font-size: 64px;
            color: #e74c3c;
            margin-bottom: 20px;
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 28px;
            font-weight: 300;
        }
        .message {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 16px;
            line-height: 1.6;
        }
        .details {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }
        .details h3 {
            margin-top: 0;
            color: #495057;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .details pre {
            background: #ffffff;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            padding: 10px;
            font-size: 12px;
            color: #6c757d;
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .contact {
            color: #6c757d;
            font-size: 14px;
        }
        .contact a {
            color: #007bff;
            text-decoration: none;
        }
        .contact a:hover {
            text-decoration: underline;
        }
        .reason-badge {
            display: inline-block;
            background: #e74c3c;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚫</div>
        
        <h1>Access Denied</h1>
        
        <div class="reason-badge">{{ ucfirst(str_replace('_', ' ', $reason)) }}</div>
        
        <div class="message">
            {{ $message }}
        </div>

        @if(!empty($details))
        <div class="details">
            <h3>Technical Details</h3>
            <pre>{{ json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
        @endif

        <div class="contact">
            <p>If you believe this is an error, please contact your system administrator or support team.</p>
            <p>Error Code: <strong>{{ strtoupper($reason) }}</strong></p>
            <p>Timestamp: <strong>{{ date('Y-m-d H:i:s') }}</strong></p>
        </div>
    </div>
</body>
</html>