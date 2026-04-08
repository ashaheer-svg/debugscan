# Domain Configuration Orchestrator (dev.activelk.com)
# Description: This script updates the Nginx and .env configuration on the live VPS.

$vps_host = "142.91.101.142"
$vps_user = "root"
$vps_pass = "z68YQFuru8QjZ4Mv"
$domain   = "dev.activelk.com"

Write-Host "--- AI DebugScan v3 - Domain Configuration Cycle Started ---" -ForegroundColor Cyan

# 1. Update Nginx Site Configuration
Write-Host "Step 1: Updating Nginx server_name..." -ForegroundColor Yellow
$nginx_cmd = "sed -i 's/server_name .*/server_name $domain;/' /etc/nginx/sites-available/aidebugscan"
plink -l $vps_user -pw $vps_pass $vps_host $nginx_cmd

# 2. Test & Reload Nginx
Write-Host "Step 2: Testing and Reloading Nginx..." -ForegroundColor Yellow
plink -l $vps_user -pw $vps_pass $vps_host "nginx -t"
plink -l $vps_user -pw $vps_pass $vps_host "systemctl reload nginx"

# 3. Update Remote .env
Write-Host "Step 3: Synchronizing Remote .env configuration..." -ForegroundColor Yellow
$env_cmd = "sed -i 's|APP_URL=.*|APP_URL=http://$domain|' /var/www/ai-debugscan3/.env"
plink -l $vps_user -pw $vps_pass $vps_host $env_cmd

# 4. Final Verification
Write-Host "Step 4: Final Domain Audit..." -ForegroundColor Yellow
plink -l $vps_user -pw $vps_pass $vps_host "grep 'server_name' /etc/nginx/sites-available/aidebugscan"

Write-Host "--- Domain Configuration Successfully Applied! ---" -ForegroundColor Green
