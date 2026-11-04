<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('hosting_smtp', function (array $config) {
            // GoDaddy authorizes the localhost relay. Verify its actual server certificate
            // while keeping the SMTP connection on loopback; never disable TLS validation.
            $transport = new EsmtpTransport('127.0.0.1', (int) ($config['port'] ?? 25), null);
            $transport->setAutoTls(true);
            $transport->setRequireTls(true);
            $transport->setLocalDomain($config['local_domain']);
            $transport->getStream()->setTimeout((int) ($config['timeout'] ?? 30));
            $options = $transport->getStream()->getStreamOptions();
            $options['ssl']['peer_name'] = $config['tls_peer_name'];
            $options['ssl']['verify_peer'] = true;
            $options['ssl']['verify_peer_name'] = true;
            $transport->getStream()->setStreamOptions($options);
            return $transport;
        });
    }
}
