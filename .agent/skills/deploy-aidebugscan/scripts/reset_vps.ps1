# VPS Reset Script - No Command Chaining
# Description: This script cleans the target VPS environment using PuTTY's plink tool.

$vps_host = "142.91.101.142"
$vps_user = "root"
$vps_pass = "z68YQFuru8QjZ4Mv"

Write-Host "--- AI DebugScan v3 - VPS Reset Cycle Started ---" -ForegroundColor Red

# 1. Stop Foreground & Background Services
Write-Host "Step 1: Stopping Services..." -ForegroundColor Yellow
plink -batch -l $vps_user -pw $vps_pass $vps_host "systemctl stop scan-worker.service"
plink -batch -l $vps_user -pw $vps_pass $vps_host "systemctl stop nginx"

# 2. Scrub Application Files
Write-Host "Step 2: Scrubbing Application Directories..." -ForegroundColor Yellow
plink -batch -l $vps_user -pw $vps_pass $vps_host "rm -rf /var/www/ai-debugscan3/*"

# 3. Reset PostgreSQL Database
Write-Host "Step 3: Resetting Database..." -ForegroundColor Yellow
plink -batch -l $vps_user -pw $vps_pass $vps_host "sudo -u postgres dropdb aidebugscan --if-exists"
plink -batch -l $vps_user -pw $vps_pass $vps_host "sudo -u postgres createdb aidebugscan"

# 4. Final Verification
Write-Host "Step 4: Verifying Cleanup..." -ForegroundColor Yellow
plink -batch -l $vps_user -pw $vps_pass $vps_host "ls -la /var/www/ai-debugscan3"

Write-Host "--- VPS Reset Successfully Completed! ---" -ForegroundColor Green
