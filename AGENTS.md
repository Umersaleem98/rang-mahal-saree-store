# Zariya Laravel project

Use Laravel 12 conventions with PHP 8.2 compatibility. Backend routes are in `routes/web.php`; APIs deliberately use the web middleware for session authentication and CSRF protection. Preserve CSRF headers in the React fetch wrapper. Frontend code lives in `resources/js`, styling in `resources/css`, and public imagery in `public/images`.

Run `npm run build` and `php artisan test` for relevant changes. Tests use transactions in a development MySQL database; never point tests at production. Do not commit `.env`, vendor dependencies, node_modules, private credentials, logs or runtime data. Serve only `public/`.

The XAMPP installation is `C:\xampp\htdocs\zariya-saree-store`, Apache address `http://localhost:8088`. This source checkout and XAMPP folder are separate copies. Preserve the XAMPP `.env` and runtime storage when syncing changes. Publish ordinary commits directly to main. Deploy Laravel through the configured GoDaddy cPanel deployment script.
