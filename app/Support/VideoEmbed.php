<?php

namespace App\Support;

final readonly class VideoEmbed
{
    private function __construct(
        public string $provider,
        public string $videoId,
    ) {}

    public static function fromUrl(?string $url): ?self
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = trim((string) ($parts['path'] ?? ''), '/');

        $youtubeId = self::youtubeId($host, $path, (string) ($parts['query'] ?? ''));

        if ($youtubeId !== null) {
            return new self('youtube', $youtubeId);
        }

        $vimeoId = self::vimeoId($host, $path);

        return $vimeoId !== null ? new self('vimeo', $vimeoId) : null;
    }

    public function embedUrl(): string
    {
        return match ($this->provider) {
            'youtube' => 'https://www.youtube-nocookie.com/embed/'.$this->videoId.'?enablejsapi=1&rel=0',
            'vimeo' => 'https://player.vimeo.com/video/'.$this->videoId.'?dnt=1',
            default => throw new \LogicException('Proveedor de video no compatible.'),
        };
    }

    private static function youtubeId(string $host, string $path, string $query): ?string
    {
        $videoId = null;

        if ($host === 'youtu.be') {
            $videoId = explode('/', $path)[0];
        } elseif (in_array($host, [
            'youtube.com',
            'm.youtube.com',
            'music.youtube.com',
            'youtube-nocookie.com',
        ], true)) {
            parse_str($query, $queryParameters);
            $segments = explode('/', $path);

            if ($path === 'watch') {
                $videoId = $queryParameters['v'] ?? null;
            } elseif (in_array($segments[0], ['embed', 'shorts', 'live'], true)) {
                $videoId = $segments[1] ?? null;
            }
        }

        return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1
            ? $videoId
            : null;
    }

    private static function vimeoId(string $host, string $path): ?string
    {
        if (! in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            return null;
        }

        $segments = array_reverse(array_filter(explode('/', $path)));

        foreach ($segments as $segment) {
            if (preg_match('/^\d{6,12}$/', $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }
}
