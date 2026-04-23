# Hardware Expansion Detection - Patch Deployment

## Quick Start

This patch contains all fixes needed to:
✅ Detect expansion units (DX513, DX517, etc.)
✅ Extract drive capacity correctly (not 0 GB)
✅ Classify drives as Main or Expansion
✅ Add report version tracking (v2.1.0)

## Deployment Steps

### On Your Local Machine (Windows)

```powershell
cd C:\Users\shahe\OneDrive\working\ai-debugscan3

# Download/copy the patch file
# (You'll get it from the workspace)

# Apply the patch
git apply hardware-expansion-fixes.patch

# Verify changes
git status

# Commit the changes
git commit -m "fix: Expansion unit detection and hardware data extraction

- Detect expansion drives by device naming pattern (sdea, sdeb, sdec...)
- Fix drive capacity extraction from load_info.result  
- Add expansion units section to reports
- Include report version tracking (v2.1.0)
- Improved drive location mapping"

# Push to GitHub
git push origin main

# Push to production server
git push production main
```

### Or Use Three Separate Commits

If you prefer to separate the changes:

```powershell
# 1. Correct drive extraction fixes
git apply --reject hardware-expansion-fixes.patch

# 2. Commit each fix separately
git add src/DeepDive/Hardware/HardwareSpecExtractor.php
git commit -m "fix: Correct drive extraction to use 'disks' array and filesystem fallbacks"

git add src/DeepDive/Hardware/ src/DeepDive/Report/ReportRenderer.php
git commit -m "feat: Comprehensive hardware extraction with expansion unit mapping"

git add src/DeepDive/Pipeline/RenderStep.php src/DeepDive/Report/ReportRenderer.php
git commit -m "fix: Improve expansion unit detection and add report version tracking"

# 3. Push all commits
git push origin main
git push production main
```

## After Deployment

1. **On production server**, regenerate a report:
   ```bash
   # Run DeepDive job with same debug bundle
   ```

2. **Expected changes in new report:**
   - ✅ Report version shows "2.1.0" in header
   - ✅ Expansion Units section appears (if expansion drives present)
   - ✅ Drives classified as "Main" or "Expansion-X"
   - ✅ Drive capacity shows actual values (not 0 GB)
   - ✅ Completeness score increases above 66%

3. **If issues occur:**
   - Check `/var/www/ai-debugscan3/src/DeepDive/Hardware/HardwareSpecExtractor.php` line 1-20 for syntax
   - Verify `load_info.result` exists in extracted debug bundle
   - Check error logs: `/var/log/apache2/error.log`

## Patch Contents

### Files Modified:
1. **src/DeepDive/Hardware/HardwareSpecExtractor.php**
   - Device pattern detection for expansion drives
   - Capacity calculation improvements
   - Enhanced expansion unit detection

2. **src/DeepDive/Pipeline/RenderStep.php**
   - Report version field added (v2.1.0)

3. **src/DeepDive/Report/ReportRenderer.php**
   - Header rendering updated with version display
   - Expansion units section added
   - Drive location information in tables
   - CSS styling for expansion table

### Total Changes:
- ~663 lines modified/added
- 3 files updated
- No database migrations needed
- No configuration changes needed

## Troubleshooting

**Patch fails to apply:**
```powershell
git apply --check hardware-expansion-fixes.patch
# Lists any conflicts
```

**Want to see what the patch contains:**
```powershell
git apply --stat hardware-expansion-fixes.patch
# Shows summary of changes
```

**Revert if needed:**
```powershell
git revert <commit-hash>
git push origin main
git push production main
```

## Support

If patch application fails:
1. Run: `git apply --reject hardware-expansion-fixes.patch`
2. Manually review `.rej` files
3. Or provide exact error message for troubleshooting

---

**Patch File:** `hardware-expansion-fixes.patch` (53 KB)
**Created:** 2026-04-23
**Target:** DeepDive v2.1.0
