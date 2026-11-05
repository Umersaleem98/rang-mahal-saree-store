# Rang Mahal production deployment

Production: https://rangmahal.site/ and https://rangmahal.site/admin.

The cPanel Git repository is `/home/jjt1xvidnzva/repositories/rangmahal`, linked to the `main` branch of `Umersaleem98/rang-mahal-saree-store` using a repository-specific read-only SSH key. Application code and the private production `.env` live in `/home/jjt1xvidnzva/apps/rangmahal`. Only public assets and the Laravel entry point are served from `/home/jjt1xvidnzva/public_html`.

## Publish an update

1. Run `npm run build`, `php artisan test`, and `git diff --check` locally. Commit the compiled `public/build` files with the source; this hosting account has no Node.js runtime.
2. Push to GitHub `main`. Use `[skip netlify]` in the commit message to avoid updating the old Next.js preview.
3. Open cPanel → Git Version Control → Manage Rang Mahal → Pull or Deploy. Click **Update from Remote**, then **Deploy HEAD Commit**. A push alone does not publish the website.

`.cpanel.yml` runs `deploy/cpanel-deploy.sh`. It preserves production credentials and storage, backs up the public files, application source, and MySQL database under `/home/jjt1xvidnzva/backups/rangmahal`, installs production Composer dependencies, migrates the database, publishes assets, and rebuilds Laravel caches. A failed database backup stops deployment before migrations. Monitor backup disk usage and copy backups off the account regularly; this is not an offsite backup service.

For the first installation, create a dedicated MySQL database/user, provision a private `.env` using `production.env.example`, and generate unique application/admin secrets. Run `php artisan db:seed --force` once after deployment. Never use `migrate:fresh` on production. Never put `.env`, database dumps, private SSH keys, or admin passwords in GitHub or `public_html`.

HTTPS and the non-www domain are canonical. Secure session cookies and CSRF protection remain enabled. Cash on delivery is enabled; online card payments remain disabled and controlled through the admin settings. Customer signup and sign-in are disabled; customers order and track as guests. Outbound customer email requires a configured mail provider; the initial configuration uses the private Laravel log.

## Recovery

Before restoring, stop incoming orders and take a new backup. Use the matching timestamped public/application/database backups from the private backup directory. Restore MySQL only with explicit approval after checking whether newer orders must be retained. The private `.env` is deliberately excluded from source archives and must be backed up separately in a secure location. Do not copy a local `.env` onto production.
