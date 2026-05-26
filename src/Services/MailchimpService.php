<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

final class MailchimpService
{
    public function subscribe(string $email, string $firstName = '', string $lastName = ''): bool
    {
        $email = sanitize_email($email);
        $apiKey = trim((string) get_option('oasebos_mailchimp_api_key', ''));
        $audienceId = trim((string) get_option('oasebos_mailchimp_audience_id', ''));

        if ($email === '' || $apiKey === '' || $audienceId === '' || ! str_contains($apiKey, '-')) {
            return false;
        }

        [, $dataCenter] = explode('-', $apiKey, 2);
        $subscriberHash = md5(strtolower($email));
        $url = sprintf('https://%s.api.mailchimp.com/3.0/lists/%s/members/%s', rawurlencode($dataCenter), rawurlencode($audienceId), $subscriberHash);
        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('oasebos:' . $apiKey),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'email_address' => $email,
                'status_if_new' => 'subscribed',
                'merge_fields' => [
                    'FNAME' => $firstName,
                    'LNAME' => $lastName,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }
}
