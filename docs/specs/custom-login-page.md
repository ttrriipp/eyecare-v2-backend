# Spec: Custom Login Page (EyeCare Admin Panel)

## Objective

Replace the default Filament login page with a custom-designed login page that matches the EyeCare brand identity. The page features a split layout: a white login form card on the left with branding, and an eyecare product image collage on the right, all against a light blue background.

**User:** Admin/staff users logging into the EyeCare management system.

**Acceptance Criteria:**
- Login page renders at `/admin/login` with the custom design
- Light blue full-page background (`#DCEEFB` or similar)
- Left side: White rounded card containing:
  - "EYECARE" heading (bold, large)
  - Tagline: *"When elegance meets convenience"* (italic)
  - Email field labeled "Username"
  - Password field with visibility toggle, labeled "Password" with "Forgot password?" link
  - "Remember me" checkbox
  - Full-width black "Login" button
- Right side: Image collage with 3 eyecare product images (asymmetric grid layout with rounded corners)
- Responsive: images hidden on smaller screens, form card centered
- Authentication still works via Filament's auth system (session-based)

## Tech Stack

- **Framework:** Laravel 13 + Filament 5 + Livewire 4
- **Styling:** Tailwind CSS 4
- **Auth:** Filament's built-in authentication (`Filament\Auth\Pages\Login`)
- **Images:** Stock photos stored at `docs/screenshots/image_login_stock/` (to be copied to `public/images/login/`)

## Commands

```
Build:  vendor/bin/sail npm run build
Test:   vendor/bin/sail artisan test --compact --filter=Login
Lint:   vendor/bin/sail bin pint --dirty --format agent
Dev:    vendor/bin/sail npm run dev
```

## Project Structure

```
app/Filament/Pages/Auth/Login.php         → Custom login page class (extends Filament base)
resources/views/filament/pages/auth/login.blade.php → Custom Blade view for login
public/images/login/                       → Login page stock images
  eyeglass1.png
  eyeglass2.png
  eyeglass3.png
tests/Feature/Filament/LoginPageTest.php   → Login page tests
```

## Code Style

Follows existing project conventions:
- PHP: PSR-12 with Pint formatting, constructor property promotion, explicit return types
- Blade: Tailwind utility classes, minimal custom CSS
- Filament: Extend base page classes, override `form()` and use custom view

Example (custom login class):
```php
<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.login';
}
```

## Testing Strategy

- **Framework:** Pest 4
- **Location:** `tests/Feature/Filament/LoginPageTest.php`
- **Test coverage:**
  - Login page renders successfully (HTTP 200)
  - Login form fields are present (email, password, remember)
  - Valid credentials authenticate and redirect to dashboard
  - Invalid credentials show error
  - Page contains EyeCare branding text

## Boundaries

- **Always:** Run tests before commits, follow existing naming conventions, use Filament's auth system
- **Ask first:** Adding new CSS files, changing panel configuration significantly
- **Never:** Bypass Filament's authentication, store credentials in views, remove existing auth functionality

## Success Criteria

1. ✅ `/admin/login` renders the custom design (not default Filament login)
2. ✅ Page has light blue background with white form card
3. ✅ "EYECARE" branding and tagline are visible
4. ✅ Form fields: email (labeled "Username"), password (with toggle), remember me, login button
5. ✅ Right side shows image collage (responsive, hidden on mobile)
6. ✅ Authentication works (login/logout flow functional)
7. ✅ All tests pass
8. ✅ Pint formatting passes

## Decisions

1. **No "Forgot password?" link** — Remove it entirely from the design.
2. **Use all 3 stock images** (eyeglass1.png, eyeglass2.png, eyeglass3.png) in the collage.
3. **Redirect `/` to login page** — Replace the welcome page route with a redirect to `/admin/login`.
