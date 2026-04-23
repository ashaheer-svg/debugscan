# Comprehensive Folder Structure & Git Tracking Audit
# Run from: C:\Users\shahe\OneDrive\working\ai-debugscan3

Write-Host "========================================"
Write-Host "DEEPDIVE FOLDER STRUCTURE AUDIT"
Write-Host "========================================"
Write-Host ""

# 1. Overall git status
Write-Host "1. GIT STATUS"
Write-Host "=================================================="
git status --short
Write-Host ""

# 2. Check for untracked files
Write-Host "2. UNTRACKED FILES (should be minimal)"
Write-Host "=================================================="
$untracked = git status --porcelain | Where-Object { $_ -match '^\?\?' }
if ($untracked) {
    $untracked
} else {
    Write-Host "[NONE]"
}
Write-Host ""

# 3. Directory structure
Write-Host "3. KEY DIRECTORIES"
Write-Host "=================================================="
$dirs = @("src", "public", "bin", "templates", "vendor", "config")
foreach ($dir in $dirs) {
    if (Test-Path $dir) {
        $fileCount = @(Get-ChildItem $dir -File -Recurse).Count
        Write-Host "[OK] $dir/ ($fileCount files)"
    } else {
        Write-Host "[MISSING] $dir/"
    }
}
Write-Host ""

# 4. Critical files check
Write-Host "4. CRITICAL FILES"
Write-Host "=================================================="
$criticalFiles = @(
    "src/DeepDive/Hardware/HardwareSpecExtractor.php",
    "src/DeepDive/Hardware/HardwareSpec.php",
    "src/DeepDive/Pipeline/RenderStep.php",
    "src/DeepDive/Pipeline/ParseStep.php",
    "src/DeepDive/Report/ReportRenderer.php",
    "public/tools/directory-browser.php",
    "public/tools/directory-viewer.html",
    "bin/list-directory.php",
    "composer.json",
    ".gitignore"
)

foreach ($file in $criticalFiles) {
    $exists = Test-Path $file
    if ($exists) {
        $gitCheck = git ls-files $file 2>$null
        if ($gitCheck) {
            $size = (Get-Item $file).Length / 1KB
            Write-Host "[OK] [TRACKED] $file ($([math]::Round($size, 1)) KB)"
        } else {
            Write-Host "[WARN] [UNTRACKED] $file"
        }
    } else {
        Write-Host "[MISSING] $file"
    }
}
Write-Host ""

# 5. Git tracking stats
Write-Host "5. GIT STATISTICS"
Write-Host "=================================================="
$totalTracked = @(git ls-files 2>$null).Count
$untracked = @(git status --porcelain 2>$null | Where-Object { $_ -match '^\?\?' }).Count
Write-Host "Total tracked files: $totalTracked"
Write-Host "Untracked files: $untracked"
Write-Host ""

# 6. Recent commits
Write-Host "6. RECENT GIT HISTORY (last 10)"
Write-Host "=================================================="
git log --oneline -10 2>$null
Write-Host ""

# 7. Branch and remote info
Write-Host "7. BRANCH & REMOTES"
Write-Host "=================================================="
$branch = git rev-parse --abbrev-ref HEAD 2>$null
Write-Host "Current branch: $branch"
Write-Host ""
Write-Host "Remotes:"
git remote -v 2>$null
Write-Host ""

# 8. Modified files
Write-Host "8. MODIFIED FILES (Uncommitted)"
Write-Host "=================================================="
$modified = @(git status --porcelain 2>$null | Where-Object { $_ -match '^ M' })
if ($modified.Count -gt 0) {
    $modified
} else {
    Write-Host "[NONE]"
}
Write-Host ""

# 9. Check DeepDive specific structure
Write-Host "9. DEEPDIVE STRUCTURE"
Write-Host "=================================================="
$deepDiveSubdirs = @(
    "src/DeepDive/Hardware",
    "src/DeepDive/Pipeline",
    "src/DeepDive/Report",
    "src/DeepDive/Rules",
    "src/DeepDive/Correlation",
    "src/DeepDive/Services"
)

foreach ($dir in $deepDiveSubdirs) {
    if (Test-Path $dir) {
        $files = @(Get-ChildItem $dir -File).Count
        Write-Host "[OK] $dir ($files files)"
    } else {
        Write-Host "[MISSING] $dir"
    }
}
Write-Host ""

# 10. Summary
Write-Host "10. ISSUES & RECOMMENDATIONS"
Write-Host "=================================================="
$issues = 0

if ($untracked -gt 10) {
    Write-Host "[WARN] Too many untracked files ($untracked)"
    $issues++
}

$missingFiles = 0
foreach ($file in $criticalFiles) {
    if (-not (Test-Path $file)) {
        $missingFiles++
    }
}

if ($missingFiles -gt 0) {
    Write-Host "[ERROR] $missingFiles critical files missing"
    $issues++
}

if ($issues -eq 0) {
    Write-Host "[OK] Repository structure looks good"
    Write-Host "[OK] All critical files present"
    Write-Host "[OK] Git tracking appears correct"
} else {
    Write-Host "[ISSUES FOUND] See details above"
}

Write-Host ""
Write-Host "========================================"
Write-Host "AUDIT COMPLETE"
Write-Host "========================================"
