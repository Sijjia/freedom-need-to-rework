<!DOCTYPE html>
<html lang="ru">
<head>
    <title>{l s='Order Processing' mod='freedompay'}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="robots" content="noindex, nofollow">
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background-color: #f8f9fa;
        }
        .processing-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .loader {
            border: 8px solid #f3f3f3;
            border-top: 8px solid #3498db;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        p {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 18px;
        }
        .manual-link {
            margin-top: 30px;
            font-size: 16px;
        }
        .manual-link a {
            color: #3498db;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
    <script>
        function checkOrder() {
            fetch('{$redirect_url|escape:'javascript':'UTF-8'}')
                .then(response => response.text())
                .then(data => {
                    if (data.includes('order-history')) {
                        window.location.reload();
                    } else {
                        setTimeout(checkOrder, 3000);
                    }
                })
                .catch(() => setTimeout(checkOrder, 3000));
        }
        
        // Запускаем проверку через 5 секунд
        setTimeout(checkOrder, 5000);
    </script>
</head>
<body>
    <div class="processing-container">
        <h1>{l s='Processing Your Order' mod='freedompay'}</h1>
        <p>{$message|escape:'html':'UTF-8'}</p>
        <div class="loader"></div>
        
        <div class="manual-link">
            {l s='If redirection does not happen automatically,' mod='freedompay'}
            <a href="{$redirect_url|escape:'html':'UTF-8'}">
                {l s='click here to view your order history' mod='freedompay'}
            </a>
        </div>
    </div>
</body>
</html>