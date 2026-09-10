<?php

declare(strict_types=1);

namespace App\Services\Install;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * "Send test email" — genuinely sends one, through a mailer that exists only for this call.
 *
 * The settings are pushed into a private mailer name rather than over `mail.mailers.smtp`, so
 * a failed test leaves nothing behind: the credentials being tried are not yet the
 * installation's credentials, and half-applying them would mean a later step sending real
 * mail through an account the administrator had already rejected.
 *
 * Transport failures are translated the same way database ones are. Symfony's SMTP exception
 * message quotes the server's reply, and a rejected AUTH exchange routinely includes the
 * username; that text belongs in the log, not in the browser.
 */
final class MailTester
{
    /**
     * A mailer name nothing else uses, so purging it cannot disturb the real one.
     */
    private const MAILER = 'planvio_installer';

    public function test(MailCredentials $credentials, string $recipient): ConnectionTest
    {
        if (! $credentials->configured || $credentials->host === '') {
            return ConnectionTest::failed(__('Enter the SMTP host before sending a test message.'));
        }

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ConnectionTest::failed(__('Enter a valid address to send the test message to.'));
        }

        config([
            'mail.mailers.'.self::MAILER => $credentials->mailerConfig(),
            'mail.from.address' => $credentials->fromAddress,
            'mail.from.name' => $credentials->fromName,
        ]);

        Mail::purge(self::MAILER);

        try {
            Mail::mailer(self::MAILER)->raw($this->body(), function (Message $message) use ($credentials, $recipient): void {
                $message->to($recipient)
                    ->from($credentials->fromAddress, $credentials->fromName)
                    ->subject(__('Planvio test message'));
            });
        } catch (TransportExceptionInterface $e) {
            return $this->explain($e, $credentials);
        } catch (Throwable $e) {
            return $this->unexpected($e);
        } finally {
            Mail::purge(self::MAILER);
        }

        return ConnectionTest::passed(
            __('Test message sent to :address.', ['address' => $recipient]),
            __('If it does not arrive within a few minutes, check the spam folder and confirm that :domain is allowed to send as :from.', [
                'domain' => $credentials->host,
                'from' => $credentials->fromAddress,
            ]),
        );
    }

    private function body(): string
    {
        return implode("\n\n", [
            __('This is a test message from your Planvio installation.'),
            __('If you are reading it, Planvio can send email: invitations, password resets, task notifications and daily digests will all reach your team.'),
            __('You can change these settings later in the admin panel.'),
        ]);
    }

    /**
     * Symfony does not give SMTP failures distinguishable types, only a message, so the
     * classification is done on the parts of that message that are protocol rather than
     * server prose: the SMTP reply code and the handful of stable phrases in its own client.
     */
    private function explain(TransportExceptionInterface $e, MailCredentials $credentials): ConnectionTest
    {
        $message = $e instanceof Throwable ? strtolower($e->getMessage()) : '';

        if (str_contains($message, 'authentication') || str_contains($message, '535') || str_contains($message, '534')) {
            return ConnectionTest::failed(
                __('The mail server rejected that username and password.'),
                __('Most hosts want the full mailbox address as the username, for example planvio@yourdomain.com rather than just planvio.'),
            );
        }

        if (str_contains($message, 'connection refused')
            || str_contains($message, 'connection could not be established')
            || str_contains($message, 'connection timed out')
            || str_contains($message, 'network is unreachable')) {
            return ConnectionTest::failed(
                __('Planvio could not reach :host on port :port.', ['host' => $credentials->host, 'port' => $credentials->port]),
                __('Check the host and port. Many hosts block outgoing connections on port 25; use 587 with STARTTLS or 465 with SSL/TLS.'),
            );
        }

        if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
            return ConnectionTest::failed(
                __('The mail server would not agree on encryption.'),
                __('Port 587 normally pairs with STARTTLS and port 465 with SSL/TLS. Try the other combination.'),
            );
        }

        if (str_contains($message, '550') || str_contains($message, '553') || str_contains($message, 'sender')) {
            return ConnectionTest::failed(
                __('The mail server refused “:from” as a sender address.', ['from' => $credentials->fromAddress]),
                __('Use an address on a domain this mail account is allowed to send for.'),
            );
        }

        return $this->unexpected($e);
    }

    private function unexpected(Throwable $e): ConnectionTest
    {
        $reference = strtoupper(Str::random(8));

        Log::error('Installer test email failed.', [
            'reference' => $reference,
            'exception' => $e::class,
        ]);

        return ConnectionTest::failed(
            __('The mail server answered with an error Planvio does not recognise.'),
            __('Check the host, port and encryption against the settings your host publishes. The technical detail was written to storage/logs.'),
            $reference,
        );
    }
}
