# 🚀 PRODUCTION DEPLOYMENT CHECKLIST

**Empower Investment Club Management System**  
**Deployment Date:** September 17, 2026  
**Target Environment:** Production Server

---

## ✅ PRE-DEPLOYMENT VERIFICATION (Complete Before Deployment)

### 1. Code & Database Status
- [x] Production readiness audit completed (95/100 score)
- [x] All critical bugs fixed (repayment search issue resolved)
- [x] Security audit passed
- [x] Data integrity verified (139 members, 0 orphans)
- [x] Trial balance verified as balanced
- [x] Accounting engine validated

### 2. Backup Current State
- [ ] Export current production database if exists
- [ ] Backup all configuration files
- [ ] Create git tag for this deployment version
  ```powershell
  git tag -a v1.0-production -m "Production deployment September 17, 2026"
  git push origin v1.0-production
  ```

### 3. Code Transfer
- [ ] Upload all project files to production server
- [ ] Verify file permissions (web server read access)
- [ ] Ensure `.htaccess` files are uploaded
- [ ] Verify `public/` directory is web-accessible
- [ ] Verify `core/`, `app/`, `database/` are outside web root OR protected

---

## 🔧 DEPLOYMENT STEPS

### STEP 1: Server Environment Setup

#### 1.1 Verify PHP Requirements
```bash
php -v  # Must be PHP 8.0 or higher
php -m  # Verify extensions: pdo, pdo_mysql, mbstring, json, openssl
```

**Required Extensions:**
- [x] PDO
- [x] PDO_MySQL
- [x] mbstring
- [x] json
- [x] openssl
- [x] session
- [x] fileinfo

#### 1.2 Verify MySQL/MariaDB
```bash
mysql --version  # Must be MySQL 5.7+ or MariaDB 10.3+
```

### STEP 2: Database Setup

#### 2.1 Create Production Database
```sql
CREATE DATABASE empower_db_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### 2.2 Create Database User
```sql
CREATE USER 'empower_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON empower_db_production.* TO 'empower_user'@'localhost';
FLUSH PRIVILEGES;
```

#### 2.3 Import Database Schema & Data
```bash
mysql -u empower_user -p empower_db_production < database/schema.sql
# OR if you have a full backup with data:
mysql -u empower_user -p empower_db_production < backup_file.sql
```

#### 2.4 Verify Database Import
```sql
USE empower_db_production;
SHOW TABLES;  # Should show 40+ tables
SELECT COUNT(*) FROM members;  # Should show 139 members
SELECT COUNT(*) FROM accounts;  # Should show 84 chart of accounts
```

### STEP 3: Environment Configuration

#### 3.1 Create Production `.env` File
```bash
cp .env.example .env
# Or create new file
```

#### 3.2 Configure Environment Variables

**Edit `.env` file with production values:**

```ini
# APPLICATION
APP_NAME="Empower Investment Club"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-domain.com

# DATABASE
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=empower_db_production
DB_USERNAME=empower_user
DB_PASSWORD=YOUR_STRONG_DATABASE_PASSWORD

# SECURITY
SESSION_LIFETIME=7200
CSRF_PROTECTION=true

# TIMEZONE
APP_TIMEZONE=Africa/Nairobi

# MAIL (SMTP Configuration)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@domain.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Empower Investment Club"

# PUSH NOTIFICATIONS (Optional)
ONESIGNAL_APP_ID=your-app-id
ONESIGNAL_REST_API_KEY=your-rest-api-key

# FILE UPLOADS
MAX_UPLOAD_SIZE=5242880
ALLOWED_FILE_TYPES=pdf,doc,docx,jpg,jpeg,png
```

#### 3.3 Verify Configuration Loading
```bash
php -r "require 'app/config/config.php'; echo APP_ENV . PHP_EOL;"
# Should output: production
```

### STEP 4: File Permissions

#### 4.1 Set Directory Permissions
```bash
# Make writable for web server (logs, cache, uploads)
chmod 755 public/uploads
chmod 755 app/logs
chmod 755 app/cache

# If these directories don't exist, create them:
mkdir -p public/uploads
mkdir -p app/logs
mkdir -p app/cache
```

#### 4.2 Secure Configuration Files
```bash
chmod 600 .env
chmod 644 app/config/*.php
```

### STEP 5: Web Server Configuration

#### 5.1 Apache Configuration (Recommended)

**VirtualHost Configuration:**
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /path/to/Empower
    
    <Directory /path/to/Empower>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/empower_error.log
    CustomLog ${APACHE_LOG_DIR}/empower_access.log combined
</VirtualHost>
```

**Enable mod_rewrite:**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### 5.2 SSL Certificate Setup (HTTPS)

**Using Let's Encrypt (Free):**
```bash
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com -d www.your-domain.com
```

**Enable HTTPS redirect in `.htaccess`:**
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### STEP 6: Application Initialization

#### 6.1 Access Application
```
https://your-domain.com
```

#### 6.2 Login with Admin Account
- Default admin credentials (should be changed immediately)
- Navigate to Settings → Change admin password

#### 6.3 Create Financial Year **CRITICAL - DO THIS FIRST!**

**Navigate to:** Settings → Financial Year Management

**Create First Financial Year:**
- **Year Name:** 2026/2027
- **Start Date:** 2026-05-01 (May 1, 2026)
- **End Date:** 2027-04-30 (April 30, 2027)
- **Status:** Active

**This MUST be done before any financial transactions!**

#### 6.4 Create Accounting Periods

The system should automatically create 12 monthly periods:
- May 2026
- June 2026
- July 2026
- ... through April 2027

**Verify in:** Accounting → Accounting Periods

### STEP 7: System Verification

#### 7.1 Test Core Functionality

**Login System:**
- [ ] Admin login works
- [ ] Regular user login works
- [ ] Password reset works

**Member Management:**
- [ ] View members list (139 members visible)
- [ ] Search members
- [ ] View individual member profile

**Savings System:**
- [ ] View savings accounts
- [ ] Test creating a savings transaction (small amount)
- [ ] Verify journal entries created
- [ ] Verify trial balance still balanced

**Loan System:**
- [ ] View active loans
- [ ] Test loan repayment (small amount)
- [ ] Verify loan schedule updates
- [ ] Verify arrears calculation

**Accounting Reports:**
- [ ] Generate Trial Balance
- [ ] Generate Income Statement
- [ ] Generate Balance Sheet
- [ ] Verify all balances match expectations

#### 7.2 Security Verification

- [ ] HTTPS enabled and working
- [ ] HTTP redirects to HTTPS
- [ ] Session cookies are secure
- [ ] CSRF protection working (try form submission)
- [ ] Unauthorized access blocked (logout, try accessing admin page)
- [ ] Directory listing disabled (try accessing `/app/`)

#### 7.3 Performance Check

- [ ] Page load times acceptable (<2 seconds)
- [ ] Database queries optimized (check slow query log)
- [ ] No PHP errors in logs

---

## 🔍 POST-DEPLOYMENT VALIDATION

### Critical Checks (Day 1)

**Hour 1:**
- [ ] All pages loading without errors
- [ ] Login/logout working
- [ ] Search functionality operational

**Hour 2-4:**
- [ ] Test 1 savings transaction
- [ ] Test 1 loan repayment
- [ ] Verify accounting entries
- [ ] Check trial balance

**Day 1 End:**
- [ ] Review error logs
- [ ] Check database connections
- [ ] Verify backup ran successfully

### First Week Monitoring

**Daily:**
- [ ] Check error logs: `tail -f app/logs/error.log`
- [ ] Verify automated backups running
- [ ] Monitor database size
- [ ] Check trial balance daily

**Weekly:**
- [ ] Generate all financial reports
- [ ] Review user feedback
- [ ] Check system performance metrics

---

## 🔒 SECURITY CHECKLIST

- [ ] Change all default passwords
- [ ] Admin password is strong (16+ characters)
- [ ] Database password is strong (20+ characters)
- [ ] `.env` file is NOT web-accessible
- [ ] `.git` directory is NOT web-accessible (should be outside web root)
- [ ] Error messages don't expose system paths
- [ ] `display_errors = Off` in production
- [ ] Database user has minimal required privileges
- [ ] HTTPS certificate is valid
- [ ] Security headers configured (HSTS, X-Frame-Options, etc.)

---

## 📊 MONITORING SETUP

### Log Monitoring

**Error Logs:**
```bash
# Check PHP errors
tail -f /var/log/apache2/empower_error.log

# Check application logs
tail -f app/logs/error.log
```

**Database Monitoring:**
```sql
-- Check database size
SELECT 
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
FROM information_schema.tables 
WHERE table_schema = 'empower_db_production';

-- Check slow queries
SHOW VARIABLES LIKE 'slow_query_log';
```

### Automated Backups

**Setup Daily Database Backup:**
```bash
#!/bin/bash
# /etc/cron.daily/empower-backup.sh

BACKUP_DIR="/backups/empower"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="empower_db_production"
DB_USER="empower_user"
DB_PASS="YOUR_PASSWORD"

mkdir -p $BACKUP_DIR
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/backup_$DATE.sql.gz

# Keep last 30 days
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +30 -delete
```

Make executable:
```bash
chmod +x /etc/cron.daily/empower-backup.sh
```

---

## 🆘 ROLLBACK PROCEDURE

### If Critical Issues Occur:

#### Quick Rollback (Immediate)
1. **Take system offline:**
   - Create maintenance page: `touch maintenance.flag`
   - Or disable VirtualHost

2. **Restore previous database:**
   ```bash
   mysql -u empower_user -p empower_db_production < backup_pre_deployment.sql
   ```

3. **Revert code if needed:**
   ```bash
   git checkout previous-stable-tag
   ```

4. **Bring system back online**

#### Investigate Issues
- Check error logs: `app/logs/error.log`
- Check Apache logs: `/var/log/apache2/empower_error.log`
- Check PHP logs: `/var/log/php_errors.log`
- Check database connectivity
- Verify environment variables loaded

---

## 📞 SUPPORT CONTACTS

### Critical Issues Contact
- **System Administrator:** [Your Contact]
- **Database Administrator:** [Your Contact]
- **Hosting Support:** [Provider Contact]

### Escalation Path
1. Check error logs
2. Review this deployment checklist
3. Contact system administrator
4. Initiate rollback if critical

---

## ✅ DEPLOYMENT SIGN-OFF

**Deployment Completed By:** ____________________  
**Date:** ____________________  
**Time:** ____________________

**Verification Completed By:** ____________________  
**Date:** ____________________

**Production Approved By:** ____________________  
**Date:** ____________________

---

## 📝 NOTES

- System deployed from audit-verified codebase (95/100 score)
- 139 members with compulsory accounts ready
- Trial balance pre-verified as balanced
- All security checks passed
- First financial year MUST be created immediately after deployment
- No test transactions in production database

**Next Review Date:** ____________________

---

**Deployment Status:** ⬜ PENDING / ⬜ IN PROGRESS / ⬜ COMPLETED / ⬜ ROLLED BACK
