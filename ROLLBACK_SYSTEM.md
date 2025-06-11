# Git-Based Rollback System

## Overview

This document describes the comprehensive git-based rollback system implemented for the CBZ Comic Reader application. The system provides safe, tracked, and automated rollback capabilities with full deployment history tracking.

## Features

### ✅ **Core Rollback Functionality**
- **Git-based rollbacks**: Uses git reset to revert to specific commits
- **Automatic backup creation**: Creates backups before rollback attempts
- **Post-rollback deployment steps**: Runs composer install, cache clear, etc.
- **Rollback validation**: Validates target commits before attempting rollback
- **Failure recovery**: Automatically restores from backup if rollback fails

### ✅ **Deployment History Tracking**
- **Complete deployment history**: Tracks all deployments with commit hashes, timestamps, and status
- **Rollback tracking**: Records when deployments are rolled back and why
- **Deployment metadata**: Stores GitHub run IDs, branches, deployment steps, and duration
- **Pagination support**: Handles large deployment histories efficiently

### ✅ **Safety Features**
- **User data protection**: Never touches user uploads or .env.local files
- **Backup system**: Creates timestamped backups before any rollback
- **Validation checks**: Ensures target commits exist and are accessible
- **Atomic operations**: Either completes fully or restores to original state
- **Confirmation prompts**: Requires explicit confirmation for rollback operations

### ✅ **Multiple Access Methods**
- **API endpoints**: RESTful API for programmatic access
- **Console commands**: CLI interface for server-side operations
- **Web interface**: Admin dashboard for easy rollback management
- **Email notifications**: Automatic notifications for rollback events

## Architecture

### Database Schema

#### DeploymentHistory Entity
```sql
CREATE TABLE deployment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commit_hash VARCHAR(40) NOT NULL,
    branch VARCHAR(255) NOT NULL,
    repository VARCHAR(255),
    github_run_id VARCHAR(255),
    deployed_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL, -- 'success', 'failed', 'rolled_back'
    deployment_steps LONGTEXT,   -- JSON of deployment steps
    duration NUMERIC(8, 2),
    deployed_by VARCHAR(255),
    rollback_reason LONGTEXT,
    rolled_back_at DATETIME,
    rolled_back_to_commit VARCHAR(40),
    INDEX idx_commit_hash (commit_hash),
    INDEX idx_status (status),
    INDEX idx_deployed_at (deployed_at)
);
```

### Service Architecture

#### RollbackService
- **Location**: `backend/src/Service/RollbackService.php`
- **Responsibilities**:
  - Validates rollback targets
  - Creates backups before rollback
  - Performs git operations
  - Runs post-rollback deployment steps
  - Sends email notifications
  - Handles failure recovery

#### DeploymentController (Enhanced)
- **Location**: `backend/src/Controller/DeploymentController.php`
- **New endpoints**:
  - `POST /api/deployment/rollback` - Perform rollback
  - `GET /api/deployment/history` - Get deployment history
  - `GET /api/deployment/rollback-targets` - Get available rollback targets
- **Enhanced webhook**: Now saves deployment history

## API Endpoints

### Rollback Management

#### Perform Rollback
```http
POST /api/deployment/rollback
Content-Type: application/json
Authorization: Admin required

{
  "commit": "abc1234567890", // Optional: specific commit hash
  "reason": "Fixing critical bug" // Optional: rollback reason
}
```

#### Get Deployment History
```http
GET /api/deployment/history?page=1&limit=20
Authorization: Admin required
```

#### Get Available Rollback Targets
```http
GET /api/deployment/rollback-targets
Authorization: Admin required
```

## Console Commands

### Rollback Command
```bash
# Rollback to previous deployment
php bin/console app:rollback

# Rollback to specific commit
php bin/console app:rollback abc1234

# Rollback with custom reason
php bin/console app:rollback --reason="Emergency rollback"

# List available rollback targets
php bin/console app:rollback --list-targets
```

## Web Interface

### Admin Dashboard Integration
- **Location**: `frontend/src/pages/RollbackManagement.jsx`
- **Features**:
  - View deployment history with status indicators
  - Quick rollback to previous deployment
  - Rollback to specific commits
  - Real-time status updates
  - Confirmation dialogs for safety

## Email Notifications

### Rollback Success Notification
- **Template**: `backend/templates/emails/rollback_success.html.twig`
- **Includes**:
  - Rollback details (from/to commits)
  - Reason for rollback
  - Executed steps with outputs
  - Backup information
  - Next steps guidance

## Configuration

### Environment Variables
```env
# Required for rollback notifications
DEPLOY_NOTIFICATION_EMAIL=admin@yourdomain.com
MAILER_FROM_ADDRESS=noreply@yourdomain.com
```

### Service Configuration
```yaml
# backend/config/services.yaml
App\Service\RollbackService:
    arguments:
        $deploymentLogger: '@monolog.logger.deployment'
        $projectDir: '%kernel.project_dir%'
```

## Usage Examples

### Emergency Rollback Scenario
1. **Detect issue** in production
2. **Quick rollback** via admin interface or console:
   ```bash
   php bin/console app:rollback --reason="Critical bug in payment system"
   ```
3. **Automatic backup** is created
4. **Git reset** to previous commit
5. **Deployment steps** run automatically
6. **Email notification** sent to admin
7. **Deployment history** updated

### Planned Rollback Scenario
1. **Review deployment history** in admin interface
2. **Select specific commit** to rollback to
3. **Confirm rollback** with reason
4. **Monitor progress** via interface
5. **Verify application** is working correctly

## Safety Guarantees

### What is Protected
- ✅ User uploads (`backend/public/uploads/`)
- ✅ Environment configuration (`.env.local`)
- ✅ Database data (only schema migrations may run)
- ✅ User sessions and authentication

### What Changes
- ✅ Application code (reverted to target commit)
- ✅ Composer dependencies (reinstalled)
- ✅ Application cache (cleared and warmed)
- ✅ Deployment history (updated with rollback record)

## Monitoring and Logging

### Deployment Logs
- **Location**: `backend/var/log/deployment/deployment-YYYY-MM-DD.log`
- **Includes**: Rollback operations, backup creation, step execution

### Backup Storage
- **Location**: `backend/var/backups/`
- **Format**: `pre-rollback-YYYY-MM-DD_HH-mm-ss.json`
- **Contains**: Backup metadata and commit information

## Best Practices

### Before Rollback
1. **Identify the issue** and confirm rollback is necessary
2. **Check deployment history** to find the last known good state
3. **Notify team members** about the planned rollback
4. **Ensure database compatibility** with target commit

### During Rollback
1. **Monitor logs** for any issues
2. **Verify each step** completes successfully
3. **Check email notifications** for confirmation

### After Rollback
1. **Test critical functionality** immediately
2. **Monitor application logs** for errors
3. **Verify user data integrity**
4. **Plan fix for the original issue**

## Troubleshooting

### Common Issues

#### Rollback Fails
- Check git repository state
- Verify target commit exists
- Review deployment logs
- Restore from backup if needed

#### Database Migration Issues
- Rollback may fail if database schema is incompatible
- Manual intervention may be required
- Consider database backup/restore

#### Permission Issues
- Ensure web server has write access to project directory
- Check git repository permissions
- Verify backup directory is writable

## Future Enhancements

### Planned Features
- [ ] Database rollback integration
- [ ] Blue-green deployment support
- [ ] Automated health checks post-rollback
- [ ] Slack/Discord notifications
- [ ] Rollback scheduling
- [ ] Multi-environment support

## Integration with Existing Deployment

The rollback system integrates seamlessly with your existing GitHub Actions deployment:

1. **Normal deployments** continue to work as before
2. **Deployment history** is automatically tracked
3. **Rollback capability** is available when needed
4. **No changes** required to existing workflow

This provides a safety net for your production deployments while maintaining the current automated deployment process. 