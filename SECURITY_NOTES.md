# Security Improvements - Implementation Notes

## ✅ Completed Security Fixes

### Phase 1: Critical (COMPLETED)

#### 1. Rate Limiting Added
**File**: `routes/api.php`

All authentication endpoints now have appropriate rate limiting:
- `register/login`: 6 requests per minute
- `password/email`: 3 requests per hour
- `password/verify-code`: 10 requests per hour
- `password/reset`: 5 requests per hour
- `verify-email`: 10 requests per hour
- `resend-verify`: 3 requests per hour

This prevents:
- Brute force attacks on verification codes
- Email bombing/spam
- DoS attacks

#### 2. Verification Code Length Increased
**Files**: `AuthController.php`, `AuthorizationController.php`

Changed from 4-digit to 6-digit codes:
- Before: 10,000 possibilities (easily brute-forced)
- After: 1,000,000 possibilities
- With rate limiting: Makes brute force impractical

#### 3. Hardcoded BVN Removed
**File**: `AuthController.php` (line 173)

Changed from hardcoded `"22433145825"` to `env('DEFAULT_BVN', '')`.

**ACTION REQUIRED**: Add to your `.env` file:
```env
DEFAULT_BVN=your_bvn_here
```

---

### Phase 2: High Priority (COMPLETED)

#### 4. Information Disclosure Fixed
**File**: `AuthController.php` (login method)

Before:
- Returned specific errors: "User not found", "Account blocked", etc.
- Allowed attackers to enumerate valid accounts

After:
- Returns generic "Invalid credentials" for failed authentication
- Only shows account status AFTER successful password verification
- Prevents user enumeration attacks

#### 5. API Key Generation Improved
**File**: `helper.php` (apiKeyGen function)

Before:
```php
substr(str_shuffle(...), 0, 60) . time()  // Predictable due to time() suffix
```

After:
```php
Str::random(64)  // Cryptographically secure random string
```

#### 6. Token Expiration Added
**File**: `config/sanctum.php`

- Tokens now expire after 7 days (10,080 minutes)
- Configurable via `SANCTUM_EXPIRATION` environment variable
- Prevents indefinite validity of stolen tokens

**OPTIONAL**: Add to `.env`:
```env
SANCTUM_EXPIRATION=10080  # Change as needed (in minutes)
```

---

### Phase 3: Medium Priority (COMPLETED)

#### 7. Email Verification Logic Fixed
**File**: `AuthorizationController.php` (emailVerification method)

Before:
- Checked if code exists, then checked email separately
- Edge case: Could verify with wrong email if code matched

After:
- Single query verifying BOTH code AND email match
- More secure and efficient

#### 8. Constant-Time Password Comparison
**File**: `AuthController.php` (login method)

Before:
```php
if ($hashPassword !== $user->sPass)  // Vulnerable to timing attacks
```

After:
```php
if (!hash_equals($hashPassword, $user->sPass))  // Constant-time comparison
```

Prevents timing attacks that could leak information about password comparison.

#### 9. HTTPS Enforcement Added
**File**: `AppServiceProvider.php`

- Forces HTTPS in production environment
- All URLs will use https:// scheme
- Prevents man-in-the-middle attacks

---

## ⚠️ Phase 4: CRITICAL - Requires Manual Migration

### Password Hashing Migration

**CURRENT ISSUE**: The application uses a weak custom hash:
```php
function passwordHash($password) {
    return substr(sha1(md5($password)), 3, 10);  // Only 10 characters!
}
```

**Problems**:
1. Not salted (vulnerable to rainbow tables)
2. Only 10 characters (easily brute-forced)
3. Not using bcrypt/argon2 (industry standard)
4. Cannot upgrade existing passwords without user action

**RECOMMENDED APPROACH**:

### Option A: Gradual Migration (Recommended)

1. **Add new field** to users table:
```php
Schema::table('subscribers', function (Blueprint $table) {
    $table->string('sPassNew')->nullable();
});
```

2. **Update helper.php** to support both:
```php
function passwordHash($password): string
{
    // Keep old function for backward compatibility
    return substr(sha1(md5($password)), 3, 10);
}

function passwordHashNew($password): string
{
    return Hash::make($password);
}

function verifyPassword($password, $user): bool
{
    // Try new hash first
    if ($user->sPassNew && Hash::check($password, $user->sPassNew)) {
        return true;
    }
    
    // Fall back to old hash
    $oldHash = passwordHash($password);
    if (hash_equals($oldHash, $user->sPass)) {
        // Upgrade to new hash on successful login
        $user->sPassNew = passwordHashNew($password);
        $user->save();
        return true;
    }
    
    return false;
}
```

3. **Update AuthController login**:
```php
if (!verifyPassword($password, $user)) {
    return $this->error(['Invalid credentials.'], 401);
}
```

4. **Update new registrations** to use new hash:
```php
$user->sPassNew = passwordHashNew($validatedData['password']);
$user->sPass = ''; // Empty or dummy value
```

5. **After migration period** (e.g., 90 days):
   - Drop `sPass` column
   - Rename `sPassNew` to `sPass`
   - Remove `passwordHash()` function

### Option B: Force Password Reset (Faster but disruptive)

1. Set all `sPass` to a flag value
2. Require all users to reset password
3. Use bcrypt from day one

---

## Additional Security Recommendations

### Immediate Actions Needed

1. **Add to `.env`**:
```env
# BVN for virtual account creation
DEFAULT_BVN=

# Token expiration (7 days default)
SANCTUM_EXPIRATION=10080
```

2. **Monitor Rate Limiting**: Watch logs for throttle events to detect attacks

3. **Enable 2FA** (Future enhancement): Consider implementing two-factor authentication

### Future Enhancements

1. **Implement Failed Login Tracking**
   - Lock accounts after N failed attempts
   - Email notifications for suspicious activity

2. **Add IP Whitelisting** for admin operations

3. **Implement CSRF Protection** for state-changing operations

4. **Add Security Headers**:
   - X-Frame-Options
   - X-Content-Type-Options
   - Strict-Transport-Security

5. **Audit Logging**: Log all sensitive operations

---

## Testing Checklist

- [ ] Test rate limiting on all auth endpoints
- [ ] Verify 6-digit codes work for registration
- [ ] Verify 6-digit codes work for password reset
- [ ] Test login with invalid credentials (should say "Invalid credentials")
- [ ] Test login with valid credentials but unverified account
- [ ] Verify tokens expire after configured time
- [ ] Test email verification with wrong code
- [ ] Test HTTPS enforcement in production

---

## Deployment Notes

1. **Clear config cache**: `php artisan config:clear`
2. **Update `.env`** with new variables
3. **Inform users** about increased security
4. **Monitor** for issues in first 24 hours

---

## Compliance Notes

- Password hashing still needs GDPR/PCI compliance work (Phase 4)
- BVN handling should comply with local data protection laws
- Consider data retention policies for sessions/tokens

---

**Last Updated**: October 15, 2025
**Implemented By**: Security Audit
**Status**: Phases 1-3 Complete, Phase 4 Pending

