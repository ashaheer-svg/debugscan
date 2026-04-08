---
name: deploy-aidebugscan
description: Deploys the AI DebugScan v3 platform to the production VPS using Git push for high integrity and version control.
---

# Deploy AI DebugScan v3 via Git

This skill uses a Git-based deployment workflow to sync the local codebase with the production VPS. This method replaces the legacy PSFTP workflow to ensure better file integrity and atomic updates.

## 1. Prerequisites
- **Git**: Must be installed locally and on the VPS.
- **SSH Access**: Ensure you have SSH access to `root@142.91.101.142`.
- **Remote Config**: The `production` remote should points to `root@142.91.101.142:/var/repo/ai-debugscan3.git`.

## 2. Deployment Instructions

### 2.1 Syncing History
Before pushing, ensure your local `main` branch is clean and all large files (>100MB) are removed or ignored via `.gitignore`.

### 2.2 Execute Deployment
Run the following command from the project root:

```bash
git push production main --force
```

> [!NOTE]
> The `--force` flag is required if the Git history has been rewritten (e.g., to purge large files) or if resetting the remote repository.

### 2.3 Post-Push Hook
The VPS is configured with a `post-receive` hook that automatically checks out the code into `/var/www/ai-debugscan3`.

## 3. Preservation of Ignored Files
Files listed in `.gitignore` (like `.env` and `storage/`) are **not** updated by Git. If performing a fresh install (wiping the target directory), ensure these are backed up and restored:

1. **Backup**: `mkdir -p /tmp/deploy_backup && cp /var/www/ai-debugscan3/.env /tmp/deploy_backup/`
2. **Wipe**: `rm -rf /var/www/ai-debugscan3/*`
3. **Push**: `git push production main --force`
4. **Restore**: `cp /tmp/deploy_backup/.env /var/www/ai-debugscan3/`

## 4. Verification Steps
After deployment, verify the application state:
- Check for `index.php`: `ls -l /var/www/ai-debugscan3/public/index.php`
- Check service status: `systemctl status nginx`

## 5. Troubleshooting
- **Permission Denied**: Check permissions on `/var/repo/ai-debugscan3.git` and `/var/www/ai-debugscan3`.
- **Hook Failures**: Inspect the hook script at `/var/repo/ai-debugscan3.git/hooks/post-receive`.
