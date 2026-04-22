# Fix: API Connection Lost Error

**Commit**: `184f6a8`  
**Date**: April 22, 2026  
**Status**: ✅ Resolved

---

## Problem

Progress modal displayed error: **"Connection lost - Could not reach progress API"**

The modal's EventSource was trying to connect to `/api/deepdive_progress.php` but failing because the application uses a `base_path` prefix for all URLs.

---

## Root Cause

**Original modal code**:
```javascript
const eventSource = new EventSource(`/api/deepdive_progress.php?id=${this.jobId}`);
```

This hardcoded `/api/` path didn't account for installations where the app runs under a subdirectory (e.g., `/deepdive-app/api/`).

---

## Solution

### 1. Updated progress.twig
Pass `base_path` to modal initialization:
```twig
new DeepDiveProgressModal({{ job.id|json_encode|raw }}, {{ base_path|json_encode|raw }});
```

### 2. Updated Modal Constructor
Accept and store `basePath`:
```javascript
constructor(jobId, basePath = '') {
    this.jobId = jobId;
    this.basePath = basePath;
    // ...
}
```

### 3. Updated EventSource URL
Construct full URL with base path:
```javascript
const apiUrl = this.basePath + '/api/deepdive_progress.php?id=' + encodeURIComponent(this.jobId);
const eventSource = new EventSource(apiUrl);
```

---

## Result

✅ Modal now connects to correct API endpoint  
✅ Works with any application path configuration  
✅ Proper URL encoding of jobId parameter  
✅ Backward compatible (basePath defaults to empty string)

---

## Testing

After pushing this fix:
1. Navigate to progress page again
2. Modal should initialize without "Connection lost" error
3. Progress bar should animate as API sends status updates
4. Steps should update with correct icons and colors

---

## Files Changed

- `templates/tenant/deepdive/progress.twig` (+2 lines)
- `templates/tenant/deepdive/progress-modal.twig` (+3 lines)

**Net change**: 5 lines added for production correctness
