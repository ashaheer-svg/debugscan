# Audit Log Download - Setup Guide

**Purpose**: Enable users to download and view pipeline audit logs through web interface

---

## Components Created

### 1. LogDownloadController (src/DeepDive/Http/Controllers/LogDownloadController.php)
Handles all log-related HTTP requests:
- List available logs
- Download logs (JSON, HTML)
- View logs in browser
- Search and filter logs
- Delete logs
- Get statistics

### 2. Dashboard View (resources/views/deepdive/logs/dashboard.blade.php)
Modern web interface for:
- Browse available audit logs
- Download buttons for each format
- Live search and filtering
- Statistics (total logs, total size)
- Real-time refresh every 30 seconds
- Delete functionality with confirmation

---

## Setup Instructions

### Step 1: Add Routes

Add to `routes/web.php` or `routes/api.php`:

```php
// Web routes
Route::get('/logs', [LogDownloadController::class, 'dashboard'])->name('logs.dashboard');
Route::get('/logs/{jobId}/report', [LogDownloadController::class, 'viewReport'])->name('logs.view');
Route::get('/logs/download/{jobId}/{filename}', [LogDownloadController::class, 'downloadLog'])->name('logs.download');

// API routes
Route::prefix('api/logs')->group(function () {
    Route::get('/list', [LogDownloadController::class, 'listLogs']);
    Route::get('/{jobId}', [LogDownloadController::class, 'getLog']);
    Route::get('/search', [LogDownloadController::class, 'search']);
    Route::get('/stats', [LogDownloadController::class, 'statistics']);
    Route::get('/json/{jobId}', [LogDownloadController::class, 'getAuditJson']);
    Route::post('/delete/{jobId}', [LogDownloadController::class, 'deleteLog']);
});
```

### Step 2: Import Controller

Add import to your routes file:

```php
use App\DeepDive\Http\Controllers\LogDownloadController;
```

### Step 3: Create Logs Directory

```bash
mkdir -p storage/logs/deepdive
chmod 755 storage/logs/deepdive
```

### Step 4: Configure Log Path

Add to `config/deepdive.php`:

```php
return [
    'logging' => [
        'path' => storage_path('logs/deepdive'),
        'retention_days' => 30,  // Auto-delete logs older than 30 days
    ],
];
```

### Step 5: Test Dashboard

Visit:
```
http://your-domain/logs
```

---

## Dashboard Features

### 📊 Statistics
- Total logs count
- Total storage used
- Last update timestamp

### 🔍 Search
- Search by job ID
- Real-time filtering
- Shows matching results

### ⬇️ Download Options
For each log:
- **View** - Open HTML report in browser
- **JSON** - Download raw audit log (machine-readable)
- **HTML** - Download visual report
- **Delete** - Remove log (with confirmation)

### 🔄 Auto-Refresh
- Updates every 30 seconds
- Shows latest logs automatically
- No page reload needed

---

## API Endpoints

All endpoints return JSON responses.

### List Logs
```
GET /api/logs/list

Response:
{
  "success": true,
  "logs": [
    {
      "job_id": "abc123",
      "files": [...],
      "file_count": 3,
      "size_bytes": 1048576,
      "size_kb": 1024,
      "created_at": "2026-04-30 23:22:03",
      "modified_at": "2026-04-30 23:25:15"
    }
  ],
  "total_count": 5,
  "total_size_bytes": 5242880
}
```

### Get Log Details
```
GET /api/logs/{jobId}

Response:
{
  "success": true,
  "job_id": "abc123",
  "files": [
    {
      "filename": "audit.json",
      "size_bytes": 524288,
      "size_kb": 512,
      "modified": "2026-04-30 23:22:03"
    }
  ],
  "total_size_bytes": 1048576
}
```

### Get Audit JSON
```
GET /api/logs/json/{jobId}

Response: Raw JSON audit log
{
  "metadata": {...},
  "component_versions": {...},
  "files_extracted": {...},
  "entries": [...]
}
```

### Search Logs
```
GET /api/logs/search?q=abc123&sort=date&order=desc

Response:
{
  "success": true,
  "query": "abc123",
  "results": [...],
  "count": 2
}
```

### Get Statistics
```
GET /api/logs/stats

Response:
{
  "success": true,
  "statistics": {
    "total_logs": 10,
    "total_size_bytes": 52428800,
    "oldest_log": "2026-04-20 10:00:00",
    "newest_log": "2026-04-30 23:25:00",
    "average_size_bytes": 5242880
  }
}
```

### Delete Log
```
POST /api/logs/delete/{jobId}

Response:
{
  "success": true,
  "message": "Log deleted"
}
```

---

## Download Methods

### Method 1: Dashboard UI (Easiest)
1. Go to `http://your-domain/logs`
2. Find log by job ID or search
3. Click "JSON" or "HTML" button
4. File downloads automatically

### Method 2: Direct Link
```
http://your-domain/logs/download/{jobId}/audit.json
http://your-domain/logs/download/{jobId}/report.html
```

### Method 3: API (Programmatic)
```bash
# Download JSON
curl http://your-domain/api/logs/json/{jobId} > audit.json

# Get as attachment
curl -O http://your-domain/logs/download/{jobId}/report.html
```

### Method 4: Command Line
```bash
# List available logs
curl http://your-domain/api/logs/list | jq .

# Search logs
curl http://your-domain/api/logs/search?q=my-job-id

# Get statistics
curl http://your-domain/api/logs/stats | jq .
```

---

## Integration with Pipeline

### During Execution
After pipeline completes, save logs:

```php
use App\DeepDive\Logging\LogExporter;

// After all steps complete
$exporter = new LogExporter($ctx->logger, config('deepdive.logging.path'));
$paths = $exporter->save();

// Store reference for later
$ctx->bag['log_paths'] = $paths;
$ctx->bag['log_job_id'] = $ctx->jobId;
```

### Provide Download Link to User
In your API response or UI:

```json
{
  "job_id": "abc123",
  "status": "complete",
  "logs": {
    "dashboard": "http://your-domain/logs?job=abc123",
    "json": "http://your-domain/logs/download/abc123/audit.json",
    "html": "http://your-domain/logs/download/abc123/report.html"
  }
}
```

---

## Log Files Structure

After logs are saved:

```
storage/logs/deepdive/
├── abc123/
│   ├── audit.json          (Raw audit trail)
│   ├── report.html         (Visual HTML report)
│   └── manifest.json       (Metadata)
├── def456/
│   ├── audit.json
│   ├── report.html
│   └── manifest.json
└── ...
```

---

## Automatic Cleanup

Configure retention period in `config/deepdive.php`:

```php
'logging' => [
    'retention_days' => 30,  // Keep logs for 30 days
],
```

Run cleanup job daily:

```php
// In scheduler (app/Console/Kernel.php)
$schedule->call(function () {
    $exporter = new LogExporter($logger, config('deepdive.logging.path'));
    $deleted = $exporter->cleanup(30);
    Log::info("Deleted $deleted old audit logs");
})->daily();
```

---

## Features Walkthrough

### 1. Dashboard Home
- Shows total logs and storage used
- Lists all available logs with files
- Search bar for finding specific jobs

### 2. Viewing Logs
Click "View" to see HTML report in browser:
- Color-coded execution log
- Component versions table (deployment verification)
- File extraction summary
- Statistics and timeline

### 3. Downloading Logs
Click "JSON" or "HTML" to download:
- Auto-generates filename with job ID and timestamp
- Correct MIME type for browser handling
- Direct download without extraction

### 4. Searching
Type job ID (partial match works):
- Real-time filtering
- Shows matching results
- Can sort by date or size

### 5. Deleting
Click "Delete" to remove logs:
- Confirmation dialog prevents accidents
- Deletes entire log directory
- Cannot be recovered

---

## Security Considerations

### File Access Control
- Logs stored outside web root (storage/ directory)
- Direct filesystem access prevented by controller
- Only authenticated users should have access (add middleware)

### Add Authentication
```php
Route::middleware('auth')->group(function () {
    Route::get('/logs', [LogDownloadController::class, 'dashboard']);
    // ... other log routes
});
```

### Path Traversal Protection
- Filename validation prevents `../` attacks
- Only allows word characters and dots
- Prevents access to files outside job directory

### CORS (if needed)
```php
Route::middleware('cors')->prefix('api/logs')->group(function () {
    // API routes accessible from other domains
});
```

---

## Troubleshooting

### Logs Not Appearing
1. Check logs directory exists: `ls storage/logs/deepdive/`
2. Check permissions: `stat storage/logs/deepdive/`
3. Verify LogExporter is saving logs (should see audit.json in directory)

### Download Not Working
1. Check file exists in directory
2. Check file is readable
3. Check Laravel routes are registered
4. Check controller import is correct

### Dashboard Not Loading
1. Check route is registered
2. Check view file exists at `resources/views/deepdive/logs/dashboard.blade.php`
3. Check logs directory path in config
4. Check for PHP errors in log

### Storage Full
1. Run cleanup: `$exporter->cleanup(30)`
2. Manually delete old logs: `rm -rf storage/logs/deepdive/old_date/`
3. Configure shorter retention period

---

## Usage Example

**User Journey**:
1. User runs deepscan job (gets job ID: abc123)
2. Pipeline completes and saves logs
3. User visits `/logs`
4. Dashboard shows: 
   - abc123 with files (audit.json, report.html, manifest.json)
   - Size: 1.2 MB
   - Created: 2026-04-30 23:22:03
5. User clicks "HTML" button
6. report.html downloads as `audit_abc123_2026-04-30_2322.html`
7. User opens in browser, sees color-coded execution log
8. User checks component versions to verify deployment
9. User downloads JSON for machine analysis

---

## Files Included

- ✅ `src/DeepDive/Http/Controllers/LogDownloadController.php` (476 lines)
- ✅ `resources/views/deepdive/logs/dashboard.blade.php` (400 lines)

---

## Next Steps

1. Add routes to `routes/web.php` and `routes/api.php`
2. Create logs directory: `mkdir -p storage/logs/deepdive`
3. Test at `http://your-domain/logs`
4. Integrate LogExporter into your pipeline
5. (Optional) Add authentication middleware
6. (Optional) Configure retention policy in scheduler
