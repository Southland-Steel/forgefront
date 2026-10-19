<!DOCTYPE html>
<html>
<head>
    <title>Git Operations For ForgeFront</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
        }
        .button-container {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }
        button {
            padding: 12px 24px;
            cursor: pointer;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }
        button:hover {
            background-color: #2980b9;
        }
        #response {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .response-item {
            margin-bottom: 10px;
        }
        .response-key {
            font-weight: bold;
            color: #2c3e50;
        }
        .response-value {
            margin-left: 10px;
            color: #34495e;
        }
        .separator {
            border-top: 1px solid #ddd;
            margin: 15px 0;
            width: 100%;
            text-align: center;
            color: #999;
            padding-top: 5px;
        }
        .log-entry {
            padding: 10px 0;
        }
    </style>
</head>
<body>
<h1>Git Operations For ForgeFront Dashboard</h1>

<div class="button-container">
    <button onclick="fetchFromGithub()">Pull from GitHub</button>
    <button onclick="getStatus()">Get Status</button>
    <button onclick="getLogs()">Get Logs</button>
</div>

<div id="response"></div>

<script>
    async function fetchFromGithub() {
        const response = await fetch('git_api_proxy.php?action=fetch', { method: 'POST'});
        showResponse(await response.json());
    }

    async function getStatus() {
        const response = await fetch('git_api_proxy.php?action=status');
        showResponse(await response.json());
    }

    async function getLogs() {
        const response = await fetch('git_api_proxy.php?action=log');
        showResponse(await response.json());
    }

    function showResponse(data) {
        const responseDiv = document.getElementById('response');
        responseDiv.innerHTML = '';

        function formatResponse(obj, parentKey = '') {
            if (Array.isArray(obj)) {
                // Handle array of logs
                obj.forEach((item, index) => {
                    if (index > 0) {
                        const separator = document.createElement('div');
                        separator.className = 'separator';
                        responseDiv.appendChild(separator);
                    }
                    formatResponse(item);
                });
            } else if (typeof obj === 'object' && obj !== null) {
                // Handle object
                const div = document.createElement('div');
                div.className = 'log-entry';

                for (const [key, value] of Object.entries(obj)) {
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'response-item';

                    const keySpan = document.createElement('span');
                    keySpan.className = 'response-key';
                    keySpan.textContent = key;

                    itemDiv.appendChild(keySpan);

                    if (typeof value === 'object' && value !== null) {
                        div.appendChild(itemDiv);
                        formatResponse(value, key);
                    } else {
                        const valueSpan = document.createElement('span');
                        valueSpan.className = 'response-value';
                        valueSpan.textContent = value;
                        itemDiv.appendChild(valueSpan);
                        div.appendChild(itemDiv);
                    }
                }
                responseDiv.appendChild(div);
            }
        }

        formatResponse(data);
    }
</script>
</body>
</html>