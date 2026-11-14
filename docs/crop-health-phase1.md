# Crop.health integration — phase 1

This phase adds server-side integration plumbing only. It does not add an upload
page, call the API, store results, or modify the database.

## Local API key setup on Windows/XAMPP

1. Obtain a Crop.health API key from the Kindwise account dashboard.
2. Open **Windows Settings → System → About → Advanced system settings → Environment Variables**.
3. Under **User variables**, create `KINDWISE_CROP_HEALTH_API_KEY` and enter the key there.
4. Close and reopen the XAMPP Control Panel so Apache inherits the updated user environment.
5. Do not put the key in PHP files, this repository, browser JavaScript, command history, or logs.

`config/crop_health.php` reads this variable with `getenv()`. The repository
contains no API key. The client fails closed when the variable is missing.

## Current safeguards and limits

- `includes/crop_health_client.php` uses the fixed HTTPS identification endpoint,
  verifies the TLS certificate and host, disables redirects, and sets a 5-second
  connection timeout and a 20-second request timeout.
- It returns only crop and disease suggestion names and probabilities. Errors are
  translated to generic Arabic messages; cURL diagnostics and request headers
  are not written to logs.
- No application page calls the client. No image upload is enabled.
- The client method must not be called until a later phase validates image MIME
  from file contents, decodes the image, checks format/size/dimensions, confirms
  that the crop belongs to the signed-in farmer, and stores files outside public
  web access.
- The Crop.health API key must be used only on the PHP server. One successful
  identification can consume API credits.

## Offline checks

Run from the project directory:

```powershell
C:\xampp\php\php.exe scripts\test_crop_health_client.php
```

The tests use an explicitly marked offline fixture. It is not a disease result,
is never shown to users, and is never sent to Kindwise.