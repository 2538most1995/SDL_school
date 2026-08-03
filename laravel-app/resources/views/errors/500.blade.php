<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>500 Diagnostic Error</title>
</head>
<body style="font-family: monospace; padding: 20px; background: #fff; color: #000;">
    <h1>500 Server Exception Diagnostic</h1>
    <p><strong>Message:</strong> {{ isset($exception) ? $exception->getMessage() : 'No exception object' }}</p>
    <p><strong>Class:</strong> {{ isset($exception) ? get_class($exception) : 'N/A' }}</p>
    <p><strong>File:</strong> {{ isset($exception) ? $exception->getFile().':'.$exception->getLine() : 'N/A' }}</p>
    <h3>Stack Trace:</h3>
    <pre style="background: #f4f4f4; padding: 15px; overflow: auto; border: 1px solid #ccc;">{{ isset($exception) ? $exception->getTraceAsString() : '' }}</pre>
</body>
</html>
