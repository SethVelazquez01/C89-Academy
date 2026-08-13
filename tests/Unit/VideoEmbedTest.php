<?php

use App\Support\VideoEmbed;

it('creates privacy friendly embeds for supported YouTube URLs', function (string $url) {
    $video = VideoEmbed::fromUrl($url);

    expect($video)->not->toBeNull()
        ->and($video?->provider)->toBe('youtube')
        ->and($video?->videoId)->toBe('dQw4w9WgXcQ')
        ->and($video?->embedUrl())->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?enablejsapi=1&rel=0');
})->with([
    'standard' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'short' => 'https://youtu.be/dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'shorts' => 'https://youtube.com/shorts/dQw4w9WgXcQ',
]);

it('creates an embed for Vimeo', function () {
    $video = VideoEmbed::fromUrl('https://vimeo.com/76979871');

    expect($video)->not->toBeNull()
        ->and($video?->provider)->toBe('vimeo')
        ->and($video?->embedUrl())->toBe('https://player.vimeo.com/video/76979871?dnt=1');
});

it('rejects unsupported insecure or malformed video URLs', function (?string $url) {
    expect(VideoEmbed::fromUrl($url))->toBeNull();
})->with([
    'unsupported provider' => 'https://example.com/video',
    'insecure YouTube' => 'http://youtube.com/watch?v=dQw4w9WgXcQ',
    'malformed identifier' => 'https://youtube.com/watch?v=invalid',
    'credentials' => 'https://user:secret@youtube.com/watch?v=dQw4w9WgXcQ',
    'empty' => '',
    'null' => null,
]);
