<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordNotifier
{
    public function __construct(
        private StateStore $store
    ) {}

    public static function validWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array(
                strtolower($parts['host'] ?? ''),
                ['discord.com', 'discordapp.com'],
                true
            )
            && !isset($parts['user'], $parts['pass'])
            && preg_match(
                '~^/api/webhooks/[0-9]+/[A-Za-z0-9._-]+$~',
                $parts['path'] ?? ''
            ) === 1;
    }

    public function send(
        Server $server,
        string $event,
        string $title,
        string $description,
        bool $test = false
    ): bool {
        $settings = $this->store->read($server)['discord'];

        if (!$test && (
            !($settings['enabled'] ?? false) ||
            !($settings[$event] ?? false)
        )) {
            return false;
        }

        $encrypted = $settings['webhook_encrypted'] ?? '';

        if (!$encrypted) {
            return false;
        }

        try {
            $url = Crypt::decryptString($encrypted);

            if (!self::validWebhookUrl($url)) {
                return false;
            }

            $colors = [
                'updates' => 0xFEE75C,
                'restarting' => 0xED4245,
                'online' => 0x57F287,
                'failed' => 0xED4245,
            ];

            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false])
                ->post($url, [
                    'username' => mb_substr(
                        $settings['name'] ?: 'PZ Mod Manager',
                        0,
                        80
                    ),
                    'allowed_mentions' => ['parse' => []],
                    'embeds' => [[
                        'title' => mb_substr($title, 0, 256),
                        'description' => mb_substr(
                            $description,
                            0,
                            4000
                        ),
                        'color' => $colors[$event] ?? 0x5865F2,
                        'timestamp' => now()->toIso8601String(),
                        'footer' => [
                            'text' => (string) $server->name,
                        ],
                    ]],
                ]);

            if (!$response->successful()) {
                Log::warning('PZMM Discord webhook HTTP failure', [
                    'server_id' => $server->id,
                    'status' => $response->status(),
                ]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('PZMM Discord webhook failed', [
                'server_id' => $server->id,
                'error_class' => get_class($e),
            ]);

            return false;
        }
    }
}
