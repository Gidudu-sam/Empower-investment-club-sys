# Empower Investment Club — Quick Deployment Checklist

**Use this checklist when deploying to production**

---

## PRE-DEPLOYMENT (Do these FIRST)

### Local Preparation
- [ ] Test system on XAMPP (all features working?)
- [ ] Create fresh database backup from Settings > Database
- [ ] Document any custom configurations
- [ ] Note down admin login credentials
- [ ] Save XAMPP credentials file locations

### Server Selection
- [ ] Choose hosting provider (VPS recommended)
- [ ] Verify PHP 8.1+ available
- [ ] Verify MySQL/MariaDB 5.7+ available
- [ ] Confirm SSH access (if VPS)
- [ ] Confirm SSL certificate available (Let's Encrypt free)

---

## SERVER SETUP (VPS Only)

- [ ] Connect via SSH
- [ ] Update system: `sudo apt update && sudo apt upgrade -y`
- [ ] Install LAMP stack (Apache, MySQL, PHP 8.1+)
- [ ] Enable Apache modules: `sudo a2enmod rewrite headers ssl`
- [ ] Install Composer globally
- [ ] Configure firewall (ports 22, 80, 443 only)
- [ ] Install Certbot for SSL
- [ ] Get SSL certificate: `sudo certbot --apache -d yourdomain.com`

---

## DATABASE SETUP

- [ ] Run `sudo mysql_secure_installation`
- [ ] Create database: `CREATE DATABASE empower_db;`
- [ ] Create user: `CREATE USER 'empower_app'@'localhost' IDENTIFIED BY 'strong_password';`
- [ ] Grant permissions: `GRANT SELECT, INSERT, UPDATE, DELETE ON empower_db.* TO 'empower_app'@'localhost';`
- [ ] Flush privileges: `FLUSH PRIVILEGES;`
- [ ] **Save database password** — you need it for environment variables!

---

## ENVIRONMENT VARIABLES

Create `/etc/environment` (VPS) or use cPanel environment section:

```bash
APP_ENV=production
APP_URL=https://yourdomain.com
DB_HOST=localhost
DB_NAME=empower_db
DB_USER=empower_app
DB_PASS=your_secure_password
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-gmail-app-password
SMTP_FROM=noreply@yourdomain.com
```

- [ ] All environment variables set
- [ ] Reloaded: `source /etc/environment`
- [ ] Verified: `printenv | grep -E "APP_|DB_|SMTP_"`

---

## FILE UPLOAD

### Option A: Git (Recommended)
- [ ] Clone repository to `/var/www/html/empower`
- [ ] Run `composer install --no-dev --optimize-autoloader`

### Option B: FTP/SFTP
- [ ] Upload all files except `/vendor`, `/backups`, `/results`
- [ ] SSH in and run `composer install --no-dev --optimize-autoloader`

---

## FILE PERMISSIONS

```bash
cd /var/www/html/empower
sudo chown -R www-data:www-data .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chmod -R 775 backups
```

- [ ] Ownership set to `www-data`
- [ ] Directories: 755 permissions
- [ ] Files: 644 permissions
- [ ] Backups directory: 775 permissions

---

## APACHE CONFIGURATION

- [ ] Create virtual host: `/etc/apache2/sites-available/empower.conf`
- [ ] Configure DocumentRoot: `/var/www/html/empower`
- [ ] Set `AllowOverride All` for .htaccess
- [ ] Enable site: `sudo a2ensite empower.conf`
- [ ] Disable default: `sudo a2dissite 000-default.conf`
- [ ] Restart Apache: `sudo systemctl restart apache2`

---

## HTTPS CONFIGURATION

In `.htaccess`, uncomment these lines:
```apache
RewriteCond %{HTTPS} off
RewriteCond %{HTTP_HOST} !^localhost [NC]
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

- [ ] HTTPS redirect enabled in `.htaccess`
- [ ] SSL certificate installed and working
- [ ] HTTP → HTTPS redirect working
- [ ] Test: Visit http://yourdomain.com (should redirect to https://)

---

## DATABASE IMPORT

```bash
# Upload backup file
scp empower_backup_20260912.sql root@your-server-ip:/tmp/

# Import
mysql -u empower_app -p empower_db < /tmp/empower_backup_20260912.sql

# Verify
mysql -u empower_app -p empower_db -e "SHOW TABLES; SELECT COUNT(*) FROM members;"
```

- [ ] Database backup uploaded
- [ ] Database imported successfully
- [ ] Tables exist and have data
- [ ] Member count matches XAMPP database

---

## AUTOMATED BACKUPS

- [ ] Create backup script: `/usr/local/bin/empower-backup.sh`
- [ ] Make executable: `sudo chmod +x /usr/local/bin/empower-backup.sh`
- [ ] Test manually: `sudo /usr/local/bin/empower-backup.sh`
- [ ] Add to cron: `sudo crontab -e`
- [ ] Cron runs daily at 2 AM: `0 2 * * * /usr/local/bin/empower-backup.sh`
- [ ] Verify backup created: `ls -lh /var/backups/empower/`

---

## TESTING (CRITICAL — Don't Skip!)

### Authentication
- [ ] Admin login works
- [ ] Treasurer login works
- [ ] Member portal login works
- [ ] Password reset works
- [ ] Logout works properly

### Core Features
- [ ] Register new member
- [ ] Record savings deposit
- [ ] Record savings withdrawal
- [ ] Create loan application
- [ ] Approve loan (chairman)
- [ ] Disburse loan (loans officer)
- [ ] Record loan repayment
- [ ] Charge and mark fee paid
- [ ] Record expense
- [ ] Record other income

### Accounting
- [ ] Trial balance loads
- [ ] Income statement generates
- [ ] Balance sheet generates
- [ ] General ledger shows transactions

### Email
- [ ] Send test statement email
- [ ] Email arrives successfully
- [ ] Links in email work (point to production URL)

### Security
- [ ] HTTP redirects to HTTPS
- [ ] Can't access `/app/` directly (403 Forbidden)
- [ ] Can't access `/backups/` directly (403 Forbidden)
- [ ] Can't access `database.php` directly (403 Forbidden)
- [ ] Session persists after page reload
- [ ] CSRF protection works (try form resubmit)

---

## POST-DEPLOYMENT

### Immediate (Day 1)
- [ ] Monitor error logs: `tail -f /var/log/apache2/empower_error.log`
- [ ] Watch for any PHP errors
- [ ] Test all features with real data
- [ ] Notify users of new production URL
- [ ] Update bookmarks/shortcuts to HTTPS URL

### Week 1
- [ ] Check backup logs daily: `cat /var/log/empower-backup.log`
- [ ] Verify backups are being created
- [ ] Monitor server resources (CPU, memory, disk)
- [ ] Review user feedback on any issues

### Month 1
- [ ] Test backup restoration procedure
- [ ] Archive XAMPP installation (keep as emergency fallback)
- [ ] Review and optimize any slow queries
- [ ] Update documentation with production specifics

---

## MONITORING SETUP (Optional but Recommended)

- [ ] Set up UptimeRobot (free, monitors uptime)
- [ ] Configure email alerts for downtime
- [ ] Set up disk space monitoring
- [ ] Configure backup failure alerts

---

## EMERGENCY ROLLBACK PLAN

If deployment fails catastrophically:

1. **DNS:** Point domain back to old server (if applicable)
2. **Apache:** Disable site: `sudo a2dissite empower.conf`
3. **Database:** Keep XAMPP running as fallback
4. **Data:** Import latest production backup back to XAMPP
5. **Users:** Notify to use old URL temporarily

---

## SUCCESS CRITERIA

✅ **Deployment is successful when:**

- HTTPS works (padlock icon in browser)
- All authentication methods work
- All core features tested and working
- Emails send successfully
- Reports generate correctly
- No errors in Apache error log
- Backups are being created daily
- Users can access the system
- Performance is acceptable (page load < 3 seconds)

---

## CONTACTS & RESOURCES

**Hosting Support:**
- Provider: ___________________________
- Phone: ___________________________
- Email: ___________________________

**Technical Contacts:**
- System Admin: ___________________________
- Developer: ___________________________
- Database Admin: ___________________________

**Documentation:**
- Full deployment guide: `PRODUCTION_DEPLOYMENT_GUIDE.md`
- Security audit: `DEPLOYMENT_READINESS_AUDIT.md`
- Environment variables: `ENVIRONMENT_VARIABLES.example`

---

## COMMON ISSUES & FIXES

**"Database connection failed"**
→ Check environment variables are set: `printenv | grep DB_`

**"Email not sending"**
→ Use Gmail App Password, not account password
→ Check port 587 is open: `telnet smtp.gmail.com 587`

**".htaccess not working"**
→ Enable mod_rewrite: `sudo a2enmod rewrite`
→ Check `AllowOverride All` in virtual host

**"Permission denied" errors**
→ Fix ownership: `sudo chown -R www-data:www-data /var/www/html/empower`

**"Session not persisting"**
→ Check session directory: `ls -la /var/lib/php/sessions`

---

**PRINT THIS CHECKLIST and check off items as you complete them!**

**Estimated Total Time:** 4-6 hours (first-time deployment)

**Good luck! 🚀**
