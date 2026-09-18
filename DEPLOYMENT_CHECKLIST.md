# DEPLOYMENT CHECKLIST - Empower Investment Club

**Target:** Production Hosting  
**Date:** September 18, 2026  
**Based On:** Production Readiness Audit Report

---

## PRE-DEPLOYMENT (MUST COMPLETE)

### 1. Environment Configuration

- [ ] Set `APP_ENV=production` in environment variables
- [ ] Set `APP_URL=https://yourdomain.com` (replace with actual domain)
- [ ] Configure database credentials:
  - [ ] `DB_HOST=localhost` (or production host)
  - [ ] `DB_NAME=empower_db`
  - [ ] `DB_USER=empower_app`  
  - [ ] `DB_PASS=secure_password`
- [ ] Configure SMTP credentials:
  - [ ] `SMTP_HOST`
  - [ ] `SMTP_PORT=587`
  - [ ] `SMTP_USERNAME`
  - [ ] `SMTP_PASSWORD`
  - [ ] `SMTP_FROM=noreply@yourdomain.com`

### 2. PHP Configuration (php.ini)

- [ ] Set `display_errors = Off`
- [ ] Set `display_startup_errors = Off`
- [ ] Set `log_errors = On`
- [ ] Set `error_log = /path/to/secure/logs/php_errors.log`
- [ ] Verify `session.cookie_secure = 1` (or let app handle it)
- [ ] Verify `session.cookie_httponly = 1`

### 3. Code Cleanup

- [ ] Commit today's critical fixes:
  - InternalVoucherController.php
  - InternalVoucherModel.php
  - app/views/members/view.php
- [ ] Delete or move diagnostic scripts:
  - [ ] check_*.php files
  - [ ] verify_*.php files
  - [ ] temp_*.php files
  - [ ] investigate_*.php files
- [ ] Delete production_readiness_audit.php (or move to /maintenance/)
- [ ] Create Git tag for deployment: `v1.0.0-production`

### 4. Dependencies

- [ ] Run `composer install --no-dev --optimize-autoloader`
- [ ] Verify vendor/ directory is complete
- [ ] Verify no development dependencies included

### 5. HTTPS / SSL

- [ ] Obtain SSL certificate for domain
- [ ] Configure web server for HTTPS
- [ ] Set up HTTP → HTTPS redirect
- [ ] Test HTTPS access

### 6. Web Server Configuration

- [ ] Disable directory listing (`Options -Indexes`)
- [ ] Protect sensitive files (.env, .sql, .log, .md)
- [ ] Add security headers (X-Content-Type-Options, X-Frame-Options, etc.)
- [ ] Configure PHP handler
- [ ] Set document root to /public or appropriate directory

### 7. Database

- [ ] Import production database schema
- [ ] Verify all tables exist
- [ ] Verify foreign keys are intact
- [ ] Create database user with appropriate permissions
- [ ] Test database connection from production environment

### 8. File Permissions

- [ ] Set web root permissions: 755 (directories), 644 (files)
- [ ] Set config files: 640 or 600
- [ ] Ensure web server can read application files
- [ ] Ensure web server cannot write to application files (except uploads/cache)
- [ ] Create uploads directory if needed (outside public root)
- [ ] Create logs directory: 770 with web server group

---

## DEPLOYMENT DAY

### 9. Upload Files

- [ ] Upload application files via FTP/SFTP/Git
- [ ] Exclude:
  - .git/ (unless using Git deployment)
  - tests/
  - Temporary diagnostic scripts
  - This checklist and audit report (or move to secure location)

### 10. Initial Verification

- [ ] Access application URL
- [ ] Verify homepage loads without errors
- [ ] Check PHP error log for issues
- [ ] Verify no visible PHP errors on screen
- [ ] Test login with existing admin account
- [ ] Verify dashboard loads

### 11. Functional Testing

- [ ] Test authentication (login/logout)
- [ ] Test member profile viewing
- [ ] Verify savings accounts display
- [ ] Verify shares display correctly
- [ ] Verify loans display
- [ ] Test navigation between pages
- [ ] Verify no JavaScript console errors

### 12. Email Testing

- [ ] Send test email (password reset or notification)
- [ ] Verify email received
- [ ] Check links in email point to production domain (not localhost)

---

## POST-DEPLOYMENT (FIRST WEEK)

### 13. Security Verification

- [ ] Test HTTPS is enforced
- [ ] Verify secure cookies are set
- [ ] Test CSRF protection on forms
- [ ] Attempt unauthorized access (should be blocked)
- [ ] Verify sessions expire correctly

### 14. Monitoring Setup

- [ ] Configure server monitoring (uptime, disk space, memory)
- [ ] Set up log monitoring/rotation
- [ ] Configure error notification (email on critical errors)
- [ ] Document location of error logs
- [ ] Set up database monitoring

### 15. Backup Setup

- [ ] Implement automated daily database backups
- [ ] Configure backup retention (30 days)
- [ ] Store backups securely (outside web root)
- [ ] Test backup restoration procedure
- [ ] Document backup/restore process

### 16. Operational Procedures

- [ ] Document cron jobs needed (if any):
  - Loan interest calculation
  - Penalty application
  - Backup automation
- [ ] Create admin user guide
- [ ] Document deployment procedure for updates
- [ ] Create incident response plan
- [ ] Document disaster recovery procedure

---

## VALIDATION CHECKLIST

### Critical Workflows to Test in Production:

- [ ] User Login/Logout
- [ ] Member Creation
- [ ] Savings Deposit
- [ ] Savings Withdrawal
- [ ] Loan Creation & Approval
- [ ] Loan Disbursement
- [ ] Loan Repayment
- [ ] Fee Creation & Payment
- [ ] Internal Voucher (Create, Approve, Post)
- [ ] Shares Purchase (via Internal Voucher)
- [ ] Financial Reports (Trial Balance, GL, etc.)
- [ ] Member Statements

---

## ROLLBACK PLAN

**If critical issues arise:**

1. [ ] Take site offline (maintenance mode)
2. [ ] Restore previous database backup
3. [ ] Restore previous code version
4. [ ] Verify restoration successful
5. [ ] Document issue for post-mortem
6. [ ] Fix issue in development
7. [ ] Re-test before redeployment

---

## SIGN-OFF

**Completed By:**  
Name: _______________________  
Date: _______________________

**Verified By:**  
Name: _______________________  
Date: _______________________

**Production URL:** _______________________

**Database Backup Location:** _______________________

**Error Log Location:** _______________________

**Emergency Contact:** _______________________

---

## NOTES / ISSUES ENCOUNTERED

```
[Space for deployment notes]





```

---

**Reference:** See `PRODUCTION_READINESS_AUDIT_REPORT.md` for detailed findings and recommendations.
