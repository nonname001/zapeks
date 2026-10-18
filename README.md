# Zapeks Website

Static company website for Zapeks, Inc.

## Pages

- `about.html`: company profile, mission, values, and sourcing strengths.
- `services.html`: component sourcing and related services.
- `coverage.html`: component families, purchase-order summary, and sectors served.
- `process.html`: sourcing flow and RFQ information checklist.
- `contact.html`: sales contact and inquiry preparation form.

`index.html` redirects to `about.html`. Images and shared styles are in `assets/`.

## Preview and Deploy

Open `index.html` in a browser, or serve this directory with any static web server. No build step or package installation is required.

For hosting, upload the contents of this repository to the website's document root, keeping the `assets/` directory alongside the HTML files.

### cPanel Git Deployment

`.cpanel.yml` copies the six HTML pages and `assets/` into the cPanel account's `$HOME/public_html/` directory. No build step is required. It overwrites matching website files and leaves other files in the destination in place.

Before deploying, confirm the domain's Document Root in cPanel under Domains. If it differs from `public_html`, update `DEPLOYPATH` in `.cpanel.yml` to that directory. The deployment stops if the destination directory does not exist.

In cPanel Git Version Control, clone this repository outside the public document root, then use Manage > Pull or Deploy > Update from Remote > Deploy HEAD Commit. Private GitHub repositories require an SSH deploy key configured on the hosting account.

Deployment also runs `scripts/cpanel-homepage.sh` to prioritize the new `index.html` over an existing WordPress `index.php`. It keeps existing `.htaccess` rules and WordPress files, backs up an existing `.htaccess` outside the document root under `$HOME/.zapeks-deploy-backups/`, and adds the homepage rule only once. If a cached construction page remains after deployment, purge the hosting/CDN cache and refresh the browser.

### Automatic Deployment

Every push to GitHub `main` starts `.github/workflows/deploy-cpanel.yml`. The workflow pushes the same commit to `ssh://zapeksco@zapeks.com:7822/home/zapeksco/repositories/zapeks`, triggering cPanel's built-in deployment hook and the tasks in `.cpanel.yml`. You can also run it manually from GitHub Actions while selecting `main`.

The repository secret `CPANEL_SSH_PRIVATE_KEY` must contain the deployment private key, and its public key must be authorized for SSH access in cPanel. The workflow pins the server's public SSH key in `.github/cpanel_known_hosts`, checks authentication without interactive prompts, and never force-pushes. If hosting.com changes the server's SSH key, confirm the new fingerprint with the host before updating that file.

Deployment status and errors appear under GitHub Actions > Deploy Zapeks to cPanel. The cPanel repository working tree must be clean; edit files in GitHub or a local clone rather than directly in the cPanel repository.

## Contact Form

The form validates required fields and opens an email draft to `sales@zapeks.com`. Visitors send the draft through their email application. The site also supports copying the inquiry; there is no form submission backend or database.

## Assets

The logo came from the company presentation. Header photographs were generated for this website and are illustrative.
