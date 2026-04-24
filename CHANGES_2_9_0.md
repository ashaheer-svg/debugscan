# Version 2.9.0 - Progress Modal Optimization

## Summary
Implemented fetch-based progress polling to replace full-page reload mechanism. Eliminates page scroll/focus loss, provides smoother UX, and preserves scroll position during job monitoring.

## Changes

### 1. Templates - Progress Modal (progress.twig)
**File**: `templates/tenant/deepdive/progress.twig`

**Changes**:
- Removed automatic `location.reload()` call (was causing page scroll loss every 2 seconds)
- Implemented JavaScript fetch() polling every 2 seconds
- Fetch endpoint: `/deepdive/api/job/{jobId}/status`
- DOM updates in-place: progress bar, percentage, status text, steps list
- Dynamically updates step icons and styling based on status
- **Option B Auto-close**: Modal automatically closes 2 seconds after job completion
- Preserved modal UI styling: gradient header (#0066cc → #004a99), system-ui font family, blue color scheme
- Close button remains disabled during execution, enabled on completion

**Benefits**:
- No page reload → preserves scroll position
- Smoother UI → no flashing/layout shift
- Better responsiveness → DOM updates in real-time

### 2. Controller - JSON Status Endpoint (JobController.php)
**File**: `src/DeepDive/Controllers/JobController.php`

**New Method**: `status()`
- Returns job status as JSON
- Path: `/deepdive/api/job/{id}/status`
- Response includes:
  - `job_id`
  - `status` (queued/running/completed/failed)
  - `progress_percent` (0-100)
  - `progress_stage` (current step)
  - `error_message`
  - `steps` (array of step data with status, label, detail, error)

**Authentication**: Requires valid session (same as other DeepDive endpoints)

### 3. Routing - API Endpoint Registration (AppBootstrap.php)
**File**: `src/AppBootstrap.php`

**Changes**:
- Added route: `GET /deepdive/api/job/{id}/status` → `JobController::status()`
- Placed within authenticated DeepDive route group

### 4. Base Layout - Remove Unused Dependency (base.twig)
**File**: `templates/layout/base.twig`

**Changes**:
- Removed: `<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>`
- Reason: Alpine.js was loaded but never used in any templates
- Benefit: Reduces external dependency, eliminates unnecessary network request

**Audit Result**: No Alpine.js directives (@click, x-*, v-*) found in codebase

### 5. Version Tracking (RenderStep.php)
**File**: `src/DeepDive/Pipeline/RenderStep.php`

**Version Bump**: `2.8.1` → `2.9.0`
- Comment: "Fetch-based progress modal, no page reload; modal auto-closes on completion"
- Version appears in all generated reports for deployment confirmation

## Testing Checklist

After deployment, verify:

1. **Progress Modal Display**
   - [ ] Modal appears centered on screen with correct styling
   - [ ] Blue gradient header visible
   - [ ] Progress bar updates every 2-3 seconds
   - [ ] Step icons change (· → ⟳ running → ✓ done)
   - [ ] Error messages display correctly if job fails

2. **No Page Reload**
   - [ ] Page doesn't refresh/flash during monitoring
   - [ ] Scroll position preserved while watching progress
   - [ ] Steps list updates in-place without full reload

3. **Auto-close on Completion**
   - [ ] Modal closes automatically 2 seconds after job completes
   - [ ] Fallback page becomes visible showing:
     - Back to project link
     - Job ID and queued timestamp
   - [ ] Close button is functional during completion

4. **Report Version**
   - [ ] Report footer shows version `2.9.0`
   - [ ] Confirms code deployment successful

5. **Dependencies**
   - [ ] No console errors about missing Alpine.js
   - [ ] Page loads without Alpine from CDN

## Deployment Instructions

1. Push code changes to production
2. Restart worker processes (if not already running with auto-reload):
   ```bash
   sudo systemctl restart deepdive-worker.service
   ```
3. Test via browser: `http://server/deepdive/view/<job-id>`
4. Monitor `/var/log/app.log` for any status endpoint errors

## Rollback Plan

If issues occur:
- Revert to commit before these changes
- Restart services: `sudo systemctl restart deepdive-worker.service`
- Previous progress page had full reload every 2 seconds (slower but functional)

## Known Issues

- **Task #11 Pending**: RAM extraction value may be incorrect in some bundles
  - Current method: Reads `/dsm/proc/meminfo`, converts KB to GB
  - Need actual debug bundle showing incorrect values to diagnose
  - Possible fixes: fallback to load_info.result, handle different file formats

- Alpine.js was unused but removed from base.twig
  - Audit confirmed no usage in any templates
  - Safe to remove

## Notes

- CSS styling preserved from previous version
- Pulse animation for running steps still works (defined in modal)
- Modal backdrop (semi-transparent) unchanged
- Close button behavior: disabled during execution, functional on completion
- Fetch polling continues until job completes, then stops automatically
