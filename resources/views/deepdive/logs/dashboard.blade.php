<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DeepDive Audit Log Download</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 16px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .stat-label {
            color: #666;
            font-size: 13px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .stat-value {
            color: #333;
            font-size: 24px;
            font-weight: bold;
        }

        .controls {
            background: white;
            padding: 20px 40px;
            border-bottom: 1px solid #eee;
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-box {
            flex: 1;
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .search-box button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        .search-box button:hover {
            background: #5568d3;
        }

        .logs-container {
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .no-logs {
            padding: 60px 40px;
            text-align: center;
            color: #999;
        }

        .no-logs-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table thead {
            background: #f8f9fa;
            border-bottom: 2px solid #eee;
        }

        .logs-table th {
            padding: 15px 20px;
            text-align: left;
            color: #666;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
        }

        .logs-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }

        .logs-table tr:hover {
            background: #f8f9fa;
        }

        .job-id {
            font-family: monospace;
            color: #667eea;
            font-weight: 500;
        }

        .file-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e8eaf6;
            color: #667eea;
            border-radius: 4px;
            font-size: 12px;
            margin-right: 5px;
            font-weight: 500;
        }

        .size {
            color: #999;
            font-size: 14px;
        }

        .date {
            color: #999;
            font-size: 14px;
            white-space: nowrap;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 6px 12px;
            border-radius: 4px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #e8eaf6;
            color: #667eea;
        }

        .btn-secondary:hover {
            background: #d1d5f3;
        }

        .btn-danger {
            background: #ffebee;
            color: #c62828;
        }

        .btn-danger:hover {
            background: #ffcdd2;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        .pagination {
            padding: 20px 40px;
            text-align: center;
            color: #999;
            font-size: 14px;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #1565c0;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 DeepDive Audit Logs</h1>
            <p>Download and view execution logs from pipeline analysis jobs</p>

            <div class="stats">
                <div class="stat-box">
                    <div class="stat-label">Total Logs</div>
                    <div class="stat-value" id="total-logs">{{ $total_logs }}</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Size</div>
                    <div class="stat-value" id="total-size">{{ $total_size_gb }} GB</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Last Updated</div>
                    <div class="stat-value" id="last-updated">--:--</div>
                </div>
            </div>
        </div>

        <div class="controls">
            <div class="search-box">
                <input type="text" id="search-input" placeholder="Search by job ID...">
                <button onclick="searchLogs()">Search</button>
                <button onclick="resetSearch()" style="background: #999;">Clear</button>
            </div>
            <button onclick="refreshLogs()" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer;">
                🔄 Refresh
            </button>
        </div>

        <div class="logs-container">
            <div id="logs-list" class="loading">
                <div class="spinner"></div> Loading logs...
            </div>
        </div>
    </div>

    <script>
        // Load and display logs on page load
        window.addEventListener('load', () => {
            loadLogs();
            updateLastUpdated();
        });

        // Refresh logs every 30 seconds
        setInterval(loadLogs, 30000);

        function loadLogs() {
            const logsList = document.getElementById('logs-list');
            logsList.innerHTML = '<div class="loading"><div class="spinner"></div> Loading logs...</div>';

            fetch('/api/logs/list')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.logs.length > 0) {
                        renderLogs(data.logs);
                    } else {
                        logsList.innerHTML = '<div class="no-logs"><div class="no-logs-icon">📁</div><p>No audit logs found</p></div>';
                    }
                })
                .catch(err => {
                    logsList.innerHTML = '<div class="alert alert-error">Error loading logs: ' + err + '</div>';
                });
        }

        function renderLogs(logs) {
            const html = `
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Job ID</th>
                            <th>Files</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map(log => `
                            <tr>
                                <td><span class="job-id">${escapeHtml(log.job_id)}</span></td>
                                <td>
                                    ${log.files.map(f => `<span class="file-badge">${escapeHtml(f.filename)}</span>`).join('')}
                                </td>
                                <td><span class="size">${formatBytes(log.size_bytes)}</span></td>
                                <td><span class="date">${escapeHtml(log.created_at)}</span></td>
                                <td>
                                    <div class="actions">
                                        <a href="/logs/${log.job_id}/report" target="_blank" class="btn btn-secondary">👁️ View</a>
                                        <button onclick="downloadLog('${log.job_id}', 'audit.json')" class="btn btn-primary">⬇️ JSON</button>
                                        <button onclick="downloadLog('${log.job_id}', 'report.html')" class="btn btn-primary">⬇️ HTML</button>
                                        <button onclick="deleteLog('${log.job_id}')" class="btn btn-danger" style="font-size: 12px;">🗑️ Delete</button>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                <div class="pagination">
                    Showing ${logs.length} log${logs.length !== 1 ? 's' : ''}
                </div>
            `;
            document.getElementById('logs-list').innerHTML = html;
        }

        function downloadLog(jobId, filename) {
            const a = document.createElement('a');
            a.href = `/logs/download/${jobId}/${filename}`;
            a.click();
        }

        function deleteLog(jobId) {
            if (!confirm('Are you sure you want to delete this log? This cannot be undone.')) {
                return;
            }

            fetch(`/api/logs/delete/${jobId}`, { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Log deleted successfully');
                        loadLogs();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(err => alert('Error: ' + err));
        }

        function searchLogs() {
            const query = document.getElementById('search-input').value;
            if (!query) {
                loadLogs();
                return;
            }

            const logsList = document.getElementById('logs-list');
            logsList.innerHTML = '<div class="loading"><div class="spinner"></div> Searching...</div>';

            fetch(`/api/logs/search?q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.results.length > 0) {
                        renderLogs(data.results);
                    } else {
                        logsList.innerHTML = '<div class="no-logs"><div class="no-logs-icon">🔍</div><p>No logs found matching: ' + escapeHtml(query) + '</p></div>';
                    }
                })
                .catch(err => {
                    logsList.innerHTML = '<div class="alert alert-error">Error searching: ' + err + '</div>';
                });
        }

        function resetSearch() {
            document.getElementById('search-input').value = '';
            loadLogs();
        }

        function refreshLogs() {
            loadLogs();
        }

        function updateLastUpdated() {
            const now = new Date();
            document.getElementById('last-updated').textContent = now.toLocaleTimeString();
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        // Allow Enter key to search
        document.getElementById('search-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                searchLogs();
            }
        });
    </script>
</body>
</html>
