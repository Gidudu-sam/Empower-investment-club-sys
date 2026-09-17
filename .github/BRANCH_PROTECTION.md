# Branch Protection Configuration

This document describes the recommended branch protection rules for the Empower repository.

## 📋 How to Configure Branch Protection on GitHub

1. Go to: https://github.com/Gidudu-sam/Empower-investment-club-sys/settings/branches
2. Click **"Add rule"** or edit existing rule
3. Configure as specified below

---

## 🛡️ MAIN BRANCH PROTECTION (REQUIRED)

**Branch name pattern:** `main`

### ✅ Enable These Rules:

#### Require a pull request before merging
- [x] **Require a pull request before merging**
  - Require approvals: **1 approval minimum**
  - Dismiss stale pull request approvals when new commits are pushed: **YES**
  - Require review from Code Owners: **Optional** (if you create CODEOWNERS file)

#### Require status checks to pass before merging
- [x] **Require status checks to pass before merging**
  - Require branches to be up to date before merging: **YES**
  - Status checks that are required:
    - ☑ `validate / PHP Syntax & Security Check`

#### Additional protections
- [x] **Require conversation resolution before merging** (Recommended)
- [x] **Require signed commits** (Optional - if using GPG)
- [x] **Include administrators** (Recommended - even admins must follow rules)
- [ ] **Allow force pushes** (Keep UNCHECKED)
- [ ] **Allow deletions** (Keep UNCHECKED)

---

## 📝 RECOMMENDED: DEVELOP BRANCH PROTECTION (Optional)

If you use a `develop` branch as integration branch:

**Branch name pattern:** `develop`

### ✅ Enable These Rules:

- [x] Require a pull request before merging
  - Require approvals: **1 approval**
- [x] Require status checks to pass
  - ☑ `validate / PHP Syntax & Security Check`
- [ ] Include administrators (Can be more relaxed for develop)

---

## 🚀 FEATURE/FIX BRANCHES (No Protection Needed)

These branches are temporary and deleted after merge:
- `feature/*`
- `fix/*`
- `hotfix/*`

**No branch protection needed** - they merge into protected branches via PR.

---

## ⚡ EMERGENCY HOTFIX PROCEDURE

Even with branch protection, critical production bugs need fast response:

### Option 1: Fast-Track PR (Recommended)
1. Create `hotfix/critical-issue` branch
2. Fix and test locally
3. Push and create PR to `main`
4. **CI must still pass**
5. Get **expedited review** (same day)
6. Merge and deploy

### Option 2: Admin Override (Emergency Only)
If the system is completely down and blocking deployment:
1. Repository admins can temporarily disable branch protection
2. Push critical fix directly
3. **Re-enable protection immediately**
4. Create follow-up PR documenting the emergency fix

**⚠️ Use Option 2 only for critical production outages!**

---

## 📊 CI STATUS CHECK BEHAVIOR

### What Happens When CI Fails?

1. **Pull Request created** → GitHub Actions automatically runs
2. **CI runs checks:**
   - PHP syntax validation
   - Secret scanning
   - Composer validation
3. **If ANY check fails:**
   - ❌ PR cannot be merged
   - Red X appears on PR
   - Error details shown in Actions tab
4. **Developer must:**
   - Fix the issue locally
   - Push new commit
   - CI runs again automatically

### What Happens When CI Passes?

1. ✅ Green checkmark on PR
2. "Merge" button becomes enabled
3. PR can be reviewed and merged

---

## 👥 REQUIRED APPROVERS

### Minimum Setup:
- **1 approval required** for any PR to main
- Approver should verify:
  - Code changes are logical
  - No obvious bugs
  - Follows existing patterns
  - CI passed

### Recommended Approvers:
- Repository owner
- Senior developer
- Anyone familiar with the codebase

### Self-Approval:
- **Not recommended** - defeats purpose of review
- If working solo, at least ensure CI passes

---

## 🔐 CODEOWNERS FILE (Optional)

Create `.github/CODEOWNERS` to automatically request reviews:

```
# Global owners - notified for all changes
* @Gidudu-sam

# Specific paths can have specific owners
/app/config/ @Gidudu-sam
/database/ @Gidudu-sam
/.github/ @Gidudu-sam
```

---

## ✅ VERIFICATION CHECKLIST

After setting up branch protection:

- [ ] Tried to push directly to `main` - **blocked** ✓
- [ ] Created feature branch - **allowed** ✓
- [ ] Created PR to main - **allowed** ✓
- [ ] Tried to merge PR before CI passed - **blocked** ✓
- [ ] CI passed and got approval - **merge allowed** ✓
- [ ] After merge, feature branch deleted - **clean** ✓

---

## 📖 WORKFLOW EXAMPLE

### Normal Feature Development:

```bash
# 1. Create feature branch
git checkout main
git pull origin main
git checkout -b feature/new-member-export

# 2. Make changes and commit
git add app/controllers/MemberController.php
git commit -m "Add CSV export for members"

# 3. Push to GitHub
git push origin feature/new-member-export

# 4. On GitHub:
#    - Create Pull Request to main
#    - CI runs automatically
#    - Wait for green checkmark
#    - Request review (or auto-requested via CODEOWNERS)
#    - Get approval
#    - Merge PR

# 5. Clean up locally
git checkout main
git pull origin main
git branch -d feature/new-member-export
```

### Bug Fix:

```bash
# Same as above but use fix/ prefix
git checkout -b fix/member-search-error
# ... make changes ...
git push origin fix/member-search-error
# Create PR, CI passes, get approval, merge
```

---

## 🚨 TROUBLESHOOTING

### "CI check failed but I can't see why"
1. Go to PR → Actions tab
2. Click on failed workflow run
3. Expand failed step to see error
4. Fix locally and push again

### "PR is blocked but CI passed"
- Check if you need approval
- Check if branch is out of date (need to merge main first)
- Check if conversations need to be resolved

### "Need to bypass protection for emergency"
- Only repository admins can do this
- Go to Settings → Branches → Edit rule
- Temporarily uncheck protections
- **Remember to re-enable after emergency!**

---

## 📅 NEXT STEPS AFTER PROTECTION SETUP

1. ✅ Configure branch protection rules on GitHub
2. ✅ Test the workflow with a simple PR
3. ✅ Document any team-specific approval rules
4. ✅ Train team members on PR process
5. ⏭️ When hosting selected: Add deployment workflow

---

**Last Updated:** 2026-09-17  
**Status:** Initial setup for CI validation only (no deployment)
