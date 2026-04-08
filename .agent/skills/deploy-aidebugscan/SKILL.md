---
name: deploy-aidebugscan
description: Securely deploys the AI DebugScan v3 platform to the production VPS using PSFTP without command chaining.
---

# Deploy AI DebugScan v3 via PSFTP

This skill provides a secure, serial deployment workflow using PuTTY's PSFTP tool. It enforces a "no command chaining" policy and includes post-transfer verification.

## 1. Prerequisites
- **PSFTP (PuTTY)**: Must be installed and available in the system PATH.
- **SSH Credentials**: Ensure you have the `root` password or a private key configured.
- **Project Root**: Access this skill from the root directory of the `ai-debugscan3` project.

## 2. Deployment Instructions

### 2.1 Prepare the Transfer List
Before running the deployment, verify the target directories in `resources/psftp_batch.txt`. 

### 2.2 Execute Transfer (No Chaining)
To deploy without using chained operators (`&&` or `;`), use the provided PowerShell orchestrator. This script runs each step as a separate process.

```powershell
# From the project root
.agent/skills/deploy-aidebugscan/scripts/push_files.ps1
```

### 2.3 Environmental Reset (Caution!)
To completely wipe the VPS environment (files and database) before a fresh deployment, use the reset script. **This action is irreversible.**

```powershell
# From the project root
.agent/skills/deploy-aidebugscan/scripts/reset_vps.ps1
```

### 2.4 Manual PSFTP Command Sequence
If executing manually, run these commands in the PSFTP prompt:
1. `open root@142.91.101.142`
2. `cd /var/www/ai-debugscan3`
3. `mput -r public`
4. `mput -r src`
5. `mput -r templates`
6. `mput -r config`
7. `mput -r migrations`
8. `mput -r workers`

## 3. Verification Steps

After the transfer, run the following command within the PSFTP session to verify the file state:
```bash
ls -R /var/www/ai-debugscan3
```

Check specifically for the existence of:
- `/var/www/ai-debugscan3/public/index.php`
- `/var/www/ai-debugscan3/src/App.php`
- `/var/www/ai-debugscan3/config/database.php`

## 4. Troubleshooting
- **Permission Denied**: Ensure `root` has write access to the target path.
- **Connection Timed Out**: Verify the VPS IP `142.91.101.142` is reachable.
