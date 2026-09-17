# 🔄 PRODUCTION ROLLBACK PROCEDURE

**Empower Investment Club Management System**

---

## ⚠️ WHEN TO USE THIS PROCEDURE

Execute rollback if you encounter:

### Critical Issues (Immediate Rollback Required)
- 🔴 **System completely down** - Unable to access application
- 🔴 **Data corruption** - Database integrity compromised
- 🔴 **Security breach** - Unauthorized access detected
- 🔴 **Accounting errors** - Trial balance not balancing
- 🔴 **Critical functionality broken** - Cannot record transactions
- 🔴 **Database connection failures** - Cannot connect to database

### Major Issues (Rollback Recommended)
- 🟠 **Multiple modules failing** - More than 2 core features broken
- 🟠 **Performance degradation** - System unusably slow (>10s page loads)
- 🟠 **Widespread errors** - Errors on multiple pages/features
- 🟠 **Authentication failures** - Users cannot login

### Minor Issues (Fix Forward, Don't Rollback)
- 🟢 **Single feature bug** - One specific feature not working
- 🟢 **Cosmetic issues** - UI display problems
- 🟢 **Non-critical reports** - One report not generating
- 🟢 **Email sending issues** - Notifications not sending

---

## 🚨 IMMEDIATE RESPONSE (FIRST 5 MINUTES)

### STEP 1: Assess Impact

**Questions to Answer:**
1. Can users access the system?
2. Can users login?
3. Can transactions be recorded?
4. Is data safe and intact?
5. How many users are affected?

**Decision Matrix:**

| Impact | User Access | Data Integrity | Action |
|--------|-------------|----------------|--------|
| **CRITICAL** | ❌ No | ❌ Compromised | **IMMEDIATE ROLLBACK** |
| **HIGH** | ⚠️ Limited | ✅ Safe | **PLANNED ROLLBACK** |
| **MEDIUM** | ✅ Yes | ✅ Safe | **FIX FORWARD** |
| **LOW** | ✅ Yes | ✅ Safe | **FIX FORWARD** |

### STEP 2: Enable Maintenance Mode

**Quick Method - Create Maintenance Flag:**
```powershell
# Navigate to project root
cd C:\xampp\htdocs\Empower

# Create maintenance flag
New-Item -ItemType File -Path "maintenance.flag"
```

**Edit index.php to check for maintenance flag:**
```php
<?php
// Add at the very top of index.php
if (file_exists(__DIR__ . '/maintenance.flag')) {
    http_response_code(503);
    echo '<h1>System Maintenance</h1>';
    echo '<p>We are performing system maintenance. Please check back in 30 minutes.</p>';
    exit;
}
?>
```

**Or Use .htaccess Redirect:**
```apache
# Add to .htaccess
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/maintenance.html$
RewriteRule ^(.*)$ /maintenance.html [R=503,L]
```

### STEP 3: Notify Stakeholders

**Immediate Notification (Email/SMS):**
```
URGENT: Empower System Maintenance

The Empower system is currently offline for emergency maintenance.

- Status: Under Investigation
- Expected Resolution: [TIME]
- Impact: All users unable to access system
- Action Required: None - please wait

We will provide updates every 30 minutes.

Support Team
```

---

## 🔙 ROLLBACK PROCEDURE

### PRE-ROLLBACK CHECKLIST

Before starting rollback:

- [ ] Maintenance mode enabled
- [ ] All users notified
- [ ] Rollback decision approved (if policy requires)
- [ ] Rollback plan reviewed
- [ ] Backup of current state created (even if broken)
- [ ] Recovery team assembled

---

## OPTION 1: DATABASE-ONLY ROLLBACK

**Use when:** Code is fine but database has issues

### STEP 1: Verify Backup Availability

```powershell
# List available backups
Get-ChildItem C:\backups\empower\ | Sort-Object LastWriteTime -Descending | Select-Object -First 5

# Or on Linux:
# ls -lth /backups/empower/ | head -5
```

**Identify the correct backup:**
- Pre-deployment backup (taken just before deployment)
- Last known good backup
- Check timestamp carefully!

### STEP 2: Create Current State Backup (Even if Broken)

```powershell
# Windows (PowerShell)
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupFile = "C:\backups\empower\pre_rollback_backup_$timestamp.sql"

# Export current database
& "C:\xampp\mysql\bin\mysqldump.exe" `
    --user=empower_user `
    --password=YOUR_PASSWORD `
    --host=localhost `
    --port=3306 `
    empower_db_production > $backupFile

# Compress
Compress-Archive -Path $backupFile -DestinationPath "$backupFile.zip"
```

**Linux/Mac:**
```bash
timestamp=$(date +%Y%m%d_%H%M%S)
mysqldump -u empower_user -p empower_db_production > /backups/empower/pre_rollback_backup_$timestamp.sql
gzip /backups/empower/pre_rollback_backup_$timestamp.sql
```

### STEP 3: Stop Application (If Possible)

**Apache:**
```powershell
# Windows
Stop-Service Apache2.4

# Linux
sudo systemctl stop apache2
```

**Or just enable maintenance mode** (if already done)

### STEP 4: Restore Database from Backup

```powershell
# Windows (PowerShell)
$backupFile = "C:\backups\empower\pre_deployment_backup_20260917.sql"

# If compressed, extract first
if (Test-Path "$backupFile.gz") {
    # Extract with 7-Zip or similar
    Expand-Archive -Path "$backupFile.gz" -DestinationPath "C:\temp\"
}

# Drop and recreate database (CAUTION!)
& "C:\xampp\mysql\bin\mysql.exe" `
    --user=empower_user `
    --password=YOUR_PASSWORD `
    --execute="DROP DATABASE empower_db_production; CREATE DATABASE empower_db_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import backup
Get-Content $backupFile | & "C:\xampp\mysql\bin\mysql.exe" `
    --user=empower_user `
    --password=YOUR_PASSWORD `
    empower_db_production
```

**Linux/Mac:**
```bash
# Extract if compressed
gunzip /backups/empower/pre_deployment_backup_20260917.sql.gz

# Drop and recreate
mysql -u empower_user -p -e "DROP DATABASE empower_db_production; CREATE DATABASE empower_db_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import
mysql -u empower_user -p empower_db_production < /backups/empower/pre_deployment_backup_20260917.sql
```

### STEP 5: Verify Database Restoration

```sql
-- Connect to database
mysql -u empower_user -p empower_db_production

-- Verify tables exist
SHOW TABLES;

-- Verify row counts
SELECT 
    'members' as tbl, COUNT(*) as rows FROM members
UNION ALL
SELECT 'loans', COUNT(*) FROM loans
UNION ALL
SELECT 'savings', COUNT(*) FROM savings
UNION ALL
SELECT 'accounts', COUNT(*) FROM accounts;

-- Verify trial balance
SELECT 
    SUM(CASE WHEN dc = 'D' THEN amount ELSE 0 END) as total_debits,
    SUM(CASE WHEN dc = 'C' THEN amount ELSE 0 END) as total_credits
FROM journal_entry_lines;

-- Should be equal!
```

### STEP 6: Restart Application

```powershell
# Windows
Start-Service Apache2.4

# Linux
sudo systemctl start apache2
```

### STEP 7: Remove Maintenance Mode

```powershell
# Remove maintenance flag
Remove-Item "C:\xampp\htdocs\Empower\maintenance.flag"

# Or remove .htaccess redirect
# Edit .htaccess and remove maintenance redirect lines
```

---

## OPTION 2: FULL SYSTEM ROLLBACK (Code + Database)

**Use when:** Both code and database need to be restored

### STEP 1: Restore Code from Git

```powershell
# Navigate to project root
cd C:\xampp\htdocs\Empower

# Check current status
git status

# View available tags/commits
git tag -l
git log --oneline -10

# Rollback to previous stable version
git checkout v1.0-pre-deployment
# Or specific commit:
# git checkout abc123def

# If changes were not committed (deployment was direct file copy)
# Restore from backup archive instead:
# Extract-Archive -Path "C:\backups\empower_code_20260917.zip" -DestinationPath "C:\xampp\htdocs\Empower" -Force
```

### STEP 2: Restore Database

Follow **Option 1, Steps 2-5** above

### STEP 3: Restore Configuration Files

```powershell
# Restore .env file from backup
Copy-Item "C:\backups\empower\.env.backup" -Destination "C:\xampp\htdocs\Empower\.env" -Force

# Verify configuration
Get-Content "C:\xampp\htdocs\Empower\.env"
```

### STEP 4: Clear Any Caches

```powershell
# Clear application cache
Remove-Item "C:\xampp\htdocs\Empower\app\cache\*" -Force -Recurse

# Clear PHP OPcache (restart Apache)
Restart-Service Apache2.4
```

### STEP 5: Verify System

See **Verification** section below

---

## OPTION 3: QUICK PARTIAL ROLLBACK (Single File/Module)

**Use when:** Only specific file(s) are broken

### Rollback Single File

```powershell
# Identify the problematic file
$brokenFile = "app\controllers\LoanController.php"

# Check git history
git log --oneline -- $brokenFile

# Restore from previous commit
git checkout HEAD~1 -- $brokenFile

# Or restore from specific commit
git checkout abc123def -- $brokenFile

# Verify change
git diff $brokenFile
```

### Rollback Database Table

```sql
-- Export single table from backup
-- (Extract from full backup or use table-specific backup)

-- Drop broken table (CAUTION!)
DROP TABLE loans;

-- Recreate and import from backup
-- (Use backup .sql file filtered for that table)
mysql -u empower_user -p empower_db_production < loans_table_backup.sql
```

---

## ✅ POST-ROLLBACK VERIFICATION

### STEP 1: System Health Check

```powershell
# Check if site loads
Invoke-WebRequest -Uri "https://your-domain.com" -UseBasicParsing

# Should return HTTP 200
```

### STEP 2: Test Core Functionality

#### Test Login
- [ ] Admin can login
- [ ] Regular user can login
- [ ] Invalid credentials rejected

#### Test Member Management
- [ ] View members list
- [ ] Search members
- [ ] View member profile
- [ ] Member count matches expected (139)

#### Test Savings
- [ ] View savings accounts
- [ ] View savings transactions
- [ ] Create test savings deposit (small amount)
- [ ] Verify journal entry created

#### Test Loans
- [ ] View active loans
- [ ] View loan schedules
- [ ] Test loan repayment
- [ ] Verify arrears calculation

#### Test Accounting
- [ ] Generate Trial Balance
- [ ] Verify debits = credits
- [ ] Generate Income Statement
- [ ] Generate Balance Sheet
- [ ] View General Ledger

### STEP 3: Data Integrity Verification

```sql
-- Connect to database
mysql -u empower_user -p empower_db_production

-- Verify critical data
SELECT COUNT(*) as member_count FROM members;
-- Expected: 139

SELECT COUNT(*) as coa_count FROM accounts;
-- Expected: 84

SELECT 
    SUM(CASE WHEN dc = 'D' THEN amount ELSE 0 END) as debits,
    SUM(CASE WHEN dc = 'C' THEN amount ELSE 0 END) as credits
FROM journal_entry_lines;
-- Debits should equal Credits

-- Check for orphaned records
SELECT COUNT(*) FROM savings WHERE member_id NOT IN (SELECT id FROM members);
-- Expected: 0

SELECT COUNT(*) FROM loans WHERE member_id NOT IN (SELECT id FROM members);
-- Expected: 0
```

### STEP 4: Check Error Logs

```powershell
# Check application logs
Get-Content "C:\xampp\htdocs\Empower\app\logs\error.log" -Tail 50

# Check Apache logs
Get-Content "C:\xampp\apache\logs\error.log" -Tail 50

# Check PHP logs
Get-Content "C:\xampp\php\logs\php_error_log" -Tail 50
```

---

## 📊 ROLLBACK SUCCESS CRITERIA

System is successfully rolled back when:

- [x] Website loads without errors
- [x] Users can login
- [x] Member count is correct (139)
- [x] Trial balance is balanced (debits = credits)
- [x] All core features functional (savings, loans, reports)
- [x] No errors in logs
- [x] Performance is acceptable (<2s page loads)
- [x] Data integrity verified (no orphans)

---

## 📞 POST-ROLLBACK COMMUNICATION

### Internal Team Notification

```
RESOLVED: System Rollback Completed

The Empower system has been rolled back to the previous stable version.

- Rollback Completed: [TIME]
- Current Version: [VERSION/TAG]
- Status: System Operational
- Data Status: Restored to [DATE/TIME]
- Verification: Complete

Action Items:
1. Monitor system for next 2 hours
2. Review incident report
3. Plan corrective action
4. Schedule re-deployment

Team Lead
```

### User Notification

```
NOTICE: System Restored and Operational

The Empower system is now back online and fully operational.

We apologize for the inconvenience. The system has been restored 
to the previous stable version.

What This Means:
- System is fully functional
- Your data is safe and intact
- Any transactions during the outage may need to be re-entered
  (Staff will contact you if needed)

Thank you for your patience.

Empower Support Team
```

---

## 🔍 POST-ROLLBACK ROOT CAUSE ANALYSIS

### Required Documentation

Create incident report including:

1. **Incident Summary**
   - What happened?
   - When did it happen?
   - What was the impact?

2. **Root Cause**
   - Why did the issue occur?
   - What was missed in testing?
   - Were there warning signs?

3. **Timeline**
   - Issue detected: [TIME]
   - Rollback initiated: [TIME]
   - System restored: [TIME]
   - Total downtime: [DURATION]

4. **Data Impact**
   - Any data lost?
   - Transactions affected?
   - User impact?

5. **Corrective Actions**
   - Immediate fixes needed
   - Testing improvements
   - Deployment process changes
   - Training needs

6. **Prevention Measures**
   - Additional testing
   - Staged rollout plan
   - Better monitoring
   - Communication improvements

---

## 🚀 RE-DEPLOYMENT PLAN

After successful rollback:

### STEP 1: Fix Root Cause (Development)
- Identify exact issue
- Implement fix
- Test thoroughly in dev environment

### STEP 2: Enhanced Testing
- Unit tests for the fix
- Integration tests
- Full system regression test
- Load testing if performance-related

### STEP 3: Staged Re-Deployment
- Deploy to staging environment first
- Run full test suite
- User acceptance testing
- Leave staging live for 24-48 hours

### STEP 4: Production Re-Deployment
- Schedule during low-usage window
- Have rollback plan ready
- Monitor closely for first 24 hours
- Gradual user rollout if possible

---

## 📋 ROLLBACK CHECKLIST

### Pre-Rollback
- [ ] Issue severity assessed (Critical/High)
- [ ] Maintenance mode enabled
- [ ] Users notified
- [ ] Current state backed up
- [ ] Backup verified available
- [ ] Rollback team assembled

### During Rollback
- [ ] Database restored
- [ ] Code restored (if needed)
- [ ] Configuration restored
- [ ] Caches cleared
- [ ] Logs checked

### Post-Rollback
- [ ] System health verified
- [ ] Core functionality tested
- [ ] Data integrity verified
- [ ] Error logs clean
- [ ] Users notified
- [ ] Incident documented
- [ ] Re-deployment planned

---

## ⚠️ COMMON PITFALLS TO AVOID

❌ **DON'T:**
- Rush the rollback without verification
- Skip backing up current state (even if broken)
- Forget to notify users
- Restore wrong backup version
- Skip post-rollback testing
- Redeploy without fixing root cause

✅ **DO:**
- Follow procedure step-by-step
- Verify each step before proceeding
- Communicate clearly and often
- Document everything
- Learn from the incident
- Improve process based on lessons learned

---

**Rollback Procedure Status:** ⬜ NOT NEEDED / ⬜ IN PROGRESS / ⬜ COMPLETED

**Rollback Executed By:** ____________________  
**Date:** ____________________  
**Time Started:** ____________________  
**Time Completed:** ____________________  
**Verified By:** ____________________
