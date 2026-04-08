# PSFTP Deployment Orchestrator - No Command Chaining
# Description: This script runs PSFTP commands sequentially.

$vps_host = "142.91.101.142"
$vps_user = "root"
$vps_pass = "z68YQFuru8QjZ4Mv"
$batch_file = ".agent/skills/deploy-aidebugscan/resources/psftp_batch.txt"

Write-Host "--- AI DebugScan v3 - PSFTP Deployment Started ---" -ForegroundColor Cyan

# 1. Run PSFTP with batch file
Write-Host "Step 1: Uploading Core Directories..." -ForegroundColor Yellow
psftp -v -batch -l $vps_user -pw $vps_pass -b $batch_file $vps_host

# 2. Verify Final Setup
Write-Host "Step 2: Performing Remote Verification..." -ForegroundColor Yellow
$verify_cmd = "ls -l /var/www/ai-debugscan3/public/index.php"
psftp -batch -l $vps_user -pw $vps_pass $vps_host -cmd $verify_cmd

Write-Host "--- Deployment Process Completed! ---" -ForegroundColor Green
